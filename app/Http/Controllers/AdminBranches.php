<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Branches;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminBranches extends Controller
{
    public function index()
    {
        Branches::authorize(request()->user(), 'manage_branches');

        return Inertia::render('AdminBranches', [
            'branches' => DB::table('branches')->orderBy('id')->get(['id', 'code', 'slug', 'name', 'timezone', 'active']),
            'memberships' => DB::table('branch_memberships as membership')->join('users', 'users.id', '=', 'membership.user_id')
                ->orderBy('membership.branch_id')->orderBy('users.name')->get(['membership.*', 'users.name', 'users.email']),
            'staffUsers' => User::whereIn('role', ['admin', 'staff'])->orderBy('name')->get(['id', 'name', 'email', 'active']),
            'branchMode' => Branches::enabled(),
        ]);
    }

    public function save(Request $request, ?int $branch = null)
    {
        Branches::authorize($request->user(), 'manage_branches');
        $fields = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,31}$/D', Rule::unique('branches')->ignore($branch)],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('branches')->ignore($branch)],
            'name' => 'required|string|max:150', 'timezone' => ['required', Rule::in(['Asia/Ho_Chi_Minh'])], 'active' => 'required|boolean',
        ]);
        DB::transaction(function () use ($request, $fields, $branch) {
            Branches::authorize($request->user(), 'manage_branches');
            $before = $branch ? DB::table('branches')->where('id', $branch)->lockForUpdate()->first() : null;
            abort_if($branch && ! $before, 404);
            if ($before) {
                $changes = $fields + ['updated_at' => now()];
                if (! $before->active || ! $fields['active']) {
                    $settings = json_decode($before->settings, true, flags: JSON_THROW_ON_ERROR);
                    $changes['settings'] = json_encode(array_replace($settings, ['accepting_orders' => false]), JSON_THROW_ON_ERROR);
                }
                DB::table('branches')->where('id', $branch)->update($changes);
            } else {
                // New branches are drafts and paused even when an activation flag is forged in the creation request.
                $settings = config('bakery.defaults');
                foreach (['address', 'phone', 'email', 'bank_name', 'bank_account', 'bank_holder', 'maps_url', 'business_street_address', 'business_locality', 'business_region', 'business_postal_code'] as $key) {
                    $settings[$key] = null;
                }
                $settings = array_replace($settings, ['accepting_orders' => false, 'business_verified' => false, 'public_content_approved' => false, 'logo_approved' => false, 'regions' => []]);
                $branch = DB::table('branches')->insertGetId(array_replace($fields, ['active' => false]) + ['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                foreach (DB::table('variants')->orderBy('id')->pluck('id') as $variant) {
                    DB::table('branch_variants')->insert(['branch_id' => $branch, 'variant_id' => $variant, 'stock' => 0, 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit($request->user()->id, $branch, $before ? 'branch.updated' : 'branch.created', ['before' => $before ? array_intersect_key((array) $before, $fields) : null, 'after' => array_replace($fields, ['active' => $before ? (bool) $fields['active'] : false])]);
        }, 5);

        return back()->with('notice', $branch ? 'Đã cập nhật cơ sở. Trạng thái nhận đơn được quản lý riêng trong cấu hình cơ sở.' : 'Đã tạo cơ sở nháp, đang ẩn và tạm ngừng nhận đơn.');
    }

    public function membership(Request $request)
    {
        Branches::authorize($request->user(), 'manage_branches');
        $fields = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id', 'user_id' => 'required|integer|exists:users,id',
            'role' => 'required|in:manager,staff,kitchen,accountant,courier', 'active' => 'required|boolean', 'can_reconcile' => 'required|boolean',
        ]);
        if (in_array($fields['role'], ['kitchen', 'courier'], true) && $fields['can_reconcile']) {
            throw ValidationException::withMessages(['can_reconcile' => 'Vai trò bếp hoặc tài xế không được đối soát hoặc xử lý hoàn tiền.']);
        }
        DB::transaction(function () use ($request, $fields) {
            $ids = array_unique([$request->user()->id, (int) $fields['user_id']]);
            sort($ids);
            DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            Branches::authorize($request->user(), 'manage_branches');
            // Fresh proof uses this version, including active sessions backed by non-database drivers.
            $user = User::lockForUpdate()->findOrFail($fields['user_id']);
            abort_unless(in_array($user->role, ['admin', 'staff'], true), 422);
            DB::table('branches')->where('id', $fields['branch_id'])->lockForUpdate()->first();
            $before = DB::table('branch_memberships')->where('branch_id', $fields['branch_id'])->where('user_id', $fields['user_id'])->first();
            DB::table('branch_memberships')->updateOrInsert(['branch_id' => $fields['branch_id'], 'user_id' => $fields['user_id']], array_intersect_key($fields, array_flip(['role', 'active', 'can_reconcile'])) + ['created_at' => $before?->created_at ?? now(), 'updated_at' => now()]);
            $user->increment('authorization_version');
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }
            $this->audit($request->user()->id, (int) $fields['branch_id'], 'membership.updated', ['user_id' => $user->id, 'before' => $before ? array_intersect_key((array) $before, array_flip(['role', 'active', 'can_reconcile'])) : null, 'after' => array_intersect_key($fields, array_flip(['role', 'active', 'can_reconcile']))]);
        }, 5);

        return back()->with('notice', 'Đã cập nhật phân công cơ sở. Nhân sự cần đăng nhập và xác thực lại.');
    }

    private function audit(int $actor, int $branch, string $action, array $data): void
    {
        DB::table('branch_audits')->insert(['actor_id' => $actor, 'branch_id' => $branch, 'action' => $action, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
