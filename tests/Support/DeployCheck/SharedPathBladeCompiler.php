<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\Compiler;

/**
 * 直っていない版の Laravel のふるまい：echo の形に関わらず、コンパイル済みのファイルは1つ（既定の道）
 */
class SharedPathBladeCompiler extends BladeCompiler
{
    public function getCompiledPath($path)
    {
        return Compiler::getCompiledPath($path);
    }
}
