<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\HbxLabMatrix;
use Illuminate\Contracts\View\View;

class HbxTestMatrixController extends Controller
{
    public function __invoke(): View
    {
        return view('developer.test-matrix', [
            'items' => HbxLabMatrix::items(),
        ]);
    }
}
