<?php

namespace App\Http\Controllers;

use App\Services\Orders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminOrders extends Controller
{
    public function dashboard(Request $request)
    {
        $filters = $this->filters($request);
        $orders = $this->dateRange(DB::table('orders'), $filters, 'created_at');
        $revenue = $this->dateRange(DB::table('orders')->where('status', 'completed')->where('payment', 'paid'), $filters, 'completed_at');
        $low = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->where('products.active', true)->where('variants.active', true)->where('variants.stock', '<=', 5);

        return Inertia::render('AdminDashboard', [
            'filters' => $filters, 'total' => (clone $orders)->count(), 'revenue' => (int) $revenue->sum('total'),
            'working' => (clone $orders)->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready', 'shipping'])->count(),
            'lowCount' => (clone $low)->count(),
            'low' => $low->orderBy('variants.stock')->limit(25)->get(['products.id as product_id', 'products.name as product_name', 'variants.name', 'variants.stock']),
            'recent' => $orders->latest('id')->limit(6)->get(['id', 'code', 'name', 'status', 'payment', 'total']),
            'statuses' => Orders::STATUSES,
        ]);
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return Inertia::render('AdminOrders', [
            'orders' => $this->filtered($filters)->paginate(15, ['id', 'code', 'name', 'status', 'payment', 'method', 'total', 'created_at'])->withQueryString(),
            'filters' => $filters, 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
        ]);
    }

    public function show(Request $request, int $order)
    {
        $row = DB::table('orders')->find($order);
        abort_unless($row, 404);

        return Inertia::render('AdminOrder', [
            'order' => Orders::detail($row, internal: true), 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
            'next' => Orders::next($row), 'reconcile' => Orders::canReconcile($request->user()),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function change(Request $request, int $order)
    {
        $fields = $request->validate([
            'kind' => 'required|in:status,payment', 'value' => 'required|string|max:16', 'note' => 'nullable|string|max:250',
        ]);
        Orders::change($order, $request->user(), $fields);

        return back()->with('notice', 'Đã cập nhật đơn.');
    }

    public function export(Request $request)
    {
        $query = $this->filtered($this->filters($request));

        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Mã đơn', 'Ngày đặt', 'Khách hàng', 'Trạng thái', 'Thanh toán', 'Tổng VND'], escape: '');
            $query->reorder('id', 'desc')->chunkByIdDesc(250, function ($orders) use ($file) {
                foreach ($orders as $order) {
                    $name = preg_match('/^(?:\s*[=+@-]|[\t\r\n])/', $order->name) ? "'".$order->name : $order->name;
                    fputcsv($file, [$order->code, $order->created_at, $name, Orders::STATUSES[$order->status], Orders::PAYMENTS[$order->payment], $order->total], escape: '');
                }
            });
            fclose($file);
        }, 'don-banh-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'code' => ['nullable', 'string', 'max:22', 'regex:/^[A-Za-z0-9-]+$/'],
            'status' => ['nullable', Rule::in(array_keys(Orders::STATUSES))],
        ]);
    }

    private function filtered(array $filters): Builder
    {
        return $this->dateRange(DB::table('orders'), $filters, 'created_at')
            ->when($filters['code'] ?? null, fn ($query, $code) => $query->where('code', 'like', strtoupper($code).'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id');
    }

    private function dateRange(Builder $query, array $filters, string $column): Builder
    {
        return $query
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where($column, '>=', $date.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where($column, '<', CarbonImmutable::parse($date)->addDay()->toDateTimeString()));
    }
}
