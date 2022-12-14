<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Composer\Composer;

trait ObtainComposerTrait
{
    /**
     * @return Composer
     */
    private function obtainComposer(): Composer
    {
        if (is_callable([$this, 'requireComposer'])) {
            /** @var Composer $composer */
            $composer = $this->requireComposer(false);
            return $composer;
        }

        /**
         * @psalm-suppress DeprecatedMethod
         * @var Composer $composer
         */
        $composer = $this->getComposer(true, false);

        return $composer;
    }
}
