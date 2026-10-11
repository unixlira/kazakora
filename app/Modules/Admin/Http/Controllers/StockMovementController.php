<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockMovement;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    private const MAX_RESULTS = 500;

    public function index(): Response
    {
        return Inertia::render('Admin/Estoque/Index', [
            'movements' => StockMovement::query()
                ->with(['product:id,name,sku', 'user:id,name'])
                ->latest()
                ->limit(self::MAX_RESULTS)
                ->get(),
        ]);
    }
}
