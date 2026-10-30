<?php

namespace App\Http\Controllers;

use App\Models\Tariff;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home', [
            'tariffs' => Tariff::query()->where('is_active', true)->orderBy('price_kopecks')->get(),
        ]);
    }
}
