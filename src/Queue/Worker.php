<?php

namespace Miraqceed\Foundation\Queue;

/** CronWorker::schedule() で登録した worker の1つ（見張りと deploy-check が読む） */
final class Worker
{
    /**
     * @param  list<string>  $queues
     */
    public function __construct(
        public readonly string $connection,
        public readonly array $queues,
        public readonly int $maxTime,
        public readonly int $timeout,
        public readonly int $tries,
        public readonly int $lockMinutes,
    ) {}

    /** watched() の名前 */
    public function name(): string
    {
        return 'queue-worker:'.implode(',', $this->queues);
    }

    /** 接続の retry_after（秒）。database の接続でなければ null */
    public function retryAfter(): ?int
    {
        $settings = config("queue.connections.{$this->connection}");
        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'database') {
            return null;
        }

        return (int) ($settings['retry_after'] ?? 60);
    }

    /** 設定の誤り（値は入れない）。なければ null */
    public function problem(): ?string
    {
        $retryAfter = $this->retryAfter();
        if ($retryAfter === null) {
            return "キューの接続 {$this->connection} が database でありません";
        }

        if ($this->timeout + CronWorker::RETRY_MARGIN_SECONDS > $retryAfter) {
            return "timeout（{$this->timeout}秒）＋".CronWorker::RETRY_MARGIN_SECONDS."秒が、接続 {$this->connection} の retry_after（{$retryAfter}秒）を超えています。処理中のジョブを次の worker が取り直します";
        }

        return null;
    }
}
