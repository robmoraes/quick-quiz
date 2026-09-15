<?php

namespace App;

use App\Runtime\SecretFileLoader;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        SecretFileLoader::load();
        parent::__construct($environment, $debug);
    }
}
