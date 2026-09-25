<?php

namespace App\Core\Contracts;

use App\Core\Data\Help\ProductApiReference;

interface ProductApiDocumentationProvider
{
    public function reference(): ?ProductApiReference;
}
