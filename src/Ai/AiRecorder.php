<?php

namespace Miraqceed\Foundation\Ai;

use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Events\ClassificationFailed;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Miraqceed\Foundation\Failures\FailureReporter;
use Throwable;

/**
 * 送った後の記録。AI の会社へ1回送るごと（文の生成の段・分類）に、使用量と原価の行を1つ残し、
 * 上限に数える額をその日の合計に足す。失敗した段・分類も行を残すが、金額には足さない（回数は送る前に数えた）。
 *
 * 記録に失敗しても本筋の呼び出しを止めない・投げない（report() して続ける）。
 * 行を書けなくても合計へ足すことは試す（払った後の額が上限に入らないと、上限をすり抜けるため）。
 */
class AiRecorder
{
    public function __construct(private readonly AiStore $store) {}

    public function handleStepCompleted(StepCompleted $event): void
    {
        $this->completed('text', $event->invocationId, $event->stepNumber, get_class($event->agent),
            $event->provider, $event->model, $event->response->usage, $event->response->meta);
    }

    public function handleClassified(Classified $event): void
    {
        $this->completed('classification', $event->invocationId, null, null,
            $event->provider, $event->model, $event->response->usage, $event->response->meta);
    }

    public function handleStepFailed(StepFailed $event): void
    {
        $this->failed('text', $event->invocationId, $event->stepNumber, get_class($event->agent),
            $event->provider, $event->model, $event->exception);
    }

    public function handleClassificationFailed(ClassificationFailed $event): void
    {
        $this->failed('classification', $event->invocationId, null, null,
            $event->provider, $event->model, $event->exception);
    }

    private function completed(string $kind, string $invocationId, ?int $step, ?string $agent,
        Provider $provider, string $model, TextUsage $usage, Meta $meta): void
    {
        try {
            $responded = $meta->model;
            $cost = AiPrices::fromConfig()->cost($provider->driver(), $model, $responded, $usage);
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);

            return;
        }

        try {
            $this->store->record([
                ...$this->base($kind, 'completed', $invocationId, $step, $agent, $provider, $model),
                'responded_model' => $responded,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'cost_micro_yen' => $cost->actualMicroYen,
                'counted_micro_yen' => $cost->countedMicroYen,
                'priced' => $cost->priced,
            ]);
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);
        }

        try {
            $this->store->addCost($cost->countedMicroYen);
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);
        }
    }

    private function failed(string $kind, string $invocationId, ?int $step, ?string $agent,
        Provider $provider, string $model, Throwable $exception): void
    {
        // 上限で止めた呼び出しは送っていない（今の SDK は送る前の例外で失敗の知らせを出さないが、将来の版に備える）
        if ($exception instanceof AiLimitReached) {
            return;
        }

        try {
            $this->store->record([
                ...$this->base($kind, 'failed', $invocationId, $step, $agent, $provider, $model),
                'responded_model' => null,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_micro_yen' => null,
                'counted_micro_yen' => null,
                'priced' => false,
                // 例外の文には秘密が入りうるので、クラス名だけを残す
                'error' => mb_substr(get_class($exception), 0, 255),
            ]);
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);
        }
    }

    /** @return array<string, mixed> */
    private function base(string $kind, string $status, string $invocationId, ?int $step, ?string $agent,
        Provider $provider, string $model): array
    {
        return [
            'kind' => $kind,
            'status' => $status,
            'invocation_id' => $invocationId,
            'step_number' => $step,
            'agent' => $agent === null ? null : mb_substr($agent, 0, 255),
            'provider_name' => $provider->name(),
            'provider_driver' => $provider->driver(),
            'requested_model' => $model,
            'created_at' => now(),
        ];
    }
}
