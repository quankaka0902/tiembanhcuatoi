<?php

namespace App\Http\Controllers;

use App\Services\OrderRequests;
use App\Services\Orders;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderChanges extends Controller
{
    public function ownerForm(Request $request, string $code)
    {
        $order = $this->owned($request, $code);

        return $this->form($request, $order, OrderRequests::customerData($order, $request->user()));
    }

    public function ownerStore(Request $request, string $code)
    {
        $order = $this->owned($request, $code);
        $url = '/don-cua-toi/'.$order->code;
        try {
            OrderRequests::submitOwner($order->id, $request->user(), $request->validate(OrderRequests::rules()));
        } catch (ValidationException $failure) {
            throw $failure->redirectTo($url);
        }

        return redirect($url)->with('notice', 'Tiệm đã nhận yêu cầu và sẽ phản hồi. Giờ và đơn hiện tại vẫn được giữ đến khi tiệm chấp thuận.');
    }

    public function sendLink(Request $request, string $trackingToken)
    {
        $fields = $request->validate(['email' => 'required|email|max:254']);
        OrderRequests::requestLink($trackingToken, $fields['email']);

        return back()->with('notice', 'Nếu thông tin phù hợp và đơn còn trong mốc nhận yêu cầu, tiệm sẽ gửi liên kết thao tác riêng tới email đã đặt đơn. Liên kết có hiệu lực 15 phút.');
    }

    public function guestForm(Request $request, string $token)
    {
        try {
            [$order, $link] = OrderRequests::guestAccess($token);
        } catch (HttpExceptionInterface $failure) {
            if ($failure->getStatusCode() !== 410) {
                throw $failure;
            }

            return $this->expiredResponse($request);
        }
        $data = OrderRequests::customerData($order, guestVerified: true);
        $data['changeRequest']['submitUrl'] = '/thao-tac-don/'.$token;

        return $this->form($request, $order, $data, $link->expires_at);
    }

    public function guestStore(Request $request, string $token)
    {
        try {
            $change = OrderRequests::submitGuest($token, $request->validate(OrderRequests::rules()));
        } catch (ValidationException $failure) {
            throw $failure->redirectTo('/thao-tac-don/'.$token);
        } catch (HttpExceptionInterface $failure) {
            if ($failure->getStatusCode() !== 410) {
                throw $failure;
            }

            return $this->expiredResponse($request);
        }
        $order = Orders::query()->find($change->order_id);

        return redirect(Orders::link($order))->with('notice', 'Tiệm đã nhận yêu cầu và sẽ phản hồi. Liên kết thao tác này đã được dùng, liên kết tra cứu vẫn hoạt động.');
    }

    public function resolve(Request $request, int $requestId)
    {
        $fields = $request->validate(['decision' => 'required|in:approved,rejected', 'note' => 'required|string|max:200']);
        $change = OrderRequests::resolve($requestId, $request->user(), $fields);

        return redirect('/quan-tri/don-hang/'.$change->order_id)->with('notice', 'Đã lưu kết quả xử lý yêu cầu.');
    }

    private function owned(Request $request, string $code): object
    {
        abort_unless($request->user()?->fresh()?->active, 403);
        $order = Orders::query()->where('user_id', $request->user()->id)->where('code', $code)->first();
        abort_unless($order, 404);

        return $order;
    }

    private function form(Request $request, object $order, array $data, ?string $expiresAt = null)
    {
        return Inertia::render('OrderAction', [
            'order' => Orders::detail($order, owner: true), 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
            ...$data, 'expiresAt' => $expiresAt, 'expired' => false,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function expiredResponse(Request $request)
    {
        return Inertia::render('OrderAction', [
            'expired' => true, 'order' => null, 'expiresAt' => null, 'changeRequest' => null, 'changeRequests' => [],
        ])->toResponse($request)->setStatusCode(410)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
