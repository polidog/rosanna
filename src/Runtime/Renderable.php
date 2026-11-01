<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

interface Renderable
{
    public function render(): Element;
}
