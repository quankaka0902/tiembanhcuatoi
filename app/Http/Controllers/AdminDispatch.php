<?php

namespace App\Http\Controllers;

use App\Services\Fulfillments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminDispatch extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Fulfillments::STATUSES))], 'kind' => 'nullable|in:pickup,delivery']);

        return Inertia::render('AdminDispatch', Fulfillments::indexData($request->user(), $filters))
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function ensure(Request $request, int $order)
    {
        Fulfillments::ensure($order, $request->user(), $request->validate(['request_key' => 'required|uuid']));

        return redirect('/quan-tri/giao-nhan')->with('notice', 'Đã tiếp nhận công việc của đơn. Lịch sử giao cũ chưa được suy đoán.');
    }

    public function trip(Request $request)
    {
        Fulfillments::assignTrip($request->user(), $request->all());

        return redirect('/quan-tri/giao-nhan')->with('notice', 'Đã phân chuyến giao nội bộ. Chưa có thời gian đến dự kiến.');
    }

    public function change(Request $request, int $job)
    {
        Fulfillments::change($job, $request->user(), $request->validate(Fulfillments::rules()));

        return redirect('/quan-tri/giao-nhan')->with('notice', 'Đã cập nhật giao nhận. Thanh toán và trạng thái hoàn tất được xử lý riêng.');
    }
}
