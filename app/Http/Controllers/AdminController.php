<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Http\Requests\MemberRequest;
use App\Http\Requests\StoreMembersRequest;
use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index', ['groupCount' => Group::count(), 'memberCount' => Member::count(), 'cashCount' => CashEntry::count()]);
    }

    public function groups(Request $request): View
    {
        $q = $request->validate(['q' => 'nullable|string|max:100']);

        return view('admin.groups', ['groups' => Group::when($q['q'] ?? null, fn ($query, $search) => $query->where(fn ($sub) => $sub->where('name', 'like', "%$search%")->orWhere('code', 'like', "%$search%")))->withCount('members')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'editing' => $request->filled('edit') ? Group::findOrFail($request->integer('edit')) : null]);
    }

    public function saveGroup(GroupRequest $request, ?Group $group = null): RedirectResponse
    {
        $data = $request->validated();
        if ($group) {
            $group->update($data);
        } else {
            Group::create($data);
        }

        return redirect()->route('admin.groups')->with('status', 'Data kelompok berhasil disimpan.');
    }

    public function members(Request $request): View|RedirectResponse
    {
        $q = $request->validate(['q' => 'nullable|string|max:100', 'group_id' => 'nullable|integer|exists:groups,id', 'is_active' => 'nullable|boolean']);

        if ($request->filled('edit')) {
            return redirect()->route('admin.members.edit', $request->integer('edit'));
        }

        return view('admin.members', ['members' => Member::with('group')->when($q['q'] ?? null, fn ($query, $s) => $query->where('name', 'like', "%$s%"))->when($q['group_id'] ?? null, fn ($query, $id) => $query->where('group_id', $id))->when(isset($q['is_active']), fn ($query) => $query->where('is_active', $q['is_active']))->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'groups' => Group::orderBy('name')->get()]);
    }

    public function memberForm(?Member $member = null): View
    {
        return view('admin.member-form', ['groups' => Group::orderBy('name')->get(), 'editing' => $member]);
    }

    public function loans(Request $request): View
    {
        $data = $request->validate(['group_id' => 'nullable|integer|exists:groups,id', 'q' => 'nullable|string|max:100']);
        $loans = Loan::with(['cashEntry', 'member.group', 'installments' => fn ($q) => $q->withSum('payments', 'amount')->with(['payments' => fn ($p) => $p->orderBy('paid_at')->orderBy('id')])->orderBy('sequence')])->when($data['group_id'] ?? null, fn ($q, $id) => $q->whereHas('member', fn ($m) => $m->where('group_id', $id)))->when($data['q'] ?? null, fn ($q, $name) => $q->whereHas('member', fn ($m) => $m->where('name', 'like', "%$name%")))->orderByDesc('disbursed_at')->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.loans', ['loans' => $loans, 'groups' => Group::orderBy('name')->get()]);
    }

    public function storeMembers(StoreMembersRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data) {
            foreach ($data['members'] as $row) {
                Member::create(['group_id' => $data['group_id'], 'is_active' => $data['is_active'], 'name' => $row['name']]);
            }
        }, 3);

        return redirect()->route('admin.members', ['group_id' => $data['group_id']])
            ->with('status', count($data['members']).' anggota berhasil ditambahkan ke kelompok yang dipilih.');
    }

    public function saveMember(MemberRequest $request, ?Member $member = null): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($member, $data) {
            if ($member) {
                $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
                if ($locked->group_id !== (int) $data['group_id'] && ($locked->savings()->withTrashed()->exists() || $locked->loans()->withTrashed()->exists())) {
                    throw ValidationException::withMessages(['group_id' => 'Anggota yang memiliki riwayat transaksi tidak dapat dipindahkan ke kelompok lain. Nonaktifkan anggota bila diperlukan.']);
                }
                $locked->update($data);
            } else {
                Member::create($data);
            }
        }, 3);

        return redirect()->route('admin.members')->with('status', 'Data anggota berhasil disimpan. Riwayat transaksi tetap tersimpan.');
    }

    public function groupDetail(Group $group): View
    {
        $group->loadCount('members', 'cashEntries');

        return view('admin.group-detail', compact('group'));
    }

    public function memberDetail(Member $member): View
    {
        $member->load('group')->loadCount('loans', 'savings');

        return view('admin.member-detail', compact('member'));
    }

    public function deleteGroup(Group $group): RedirectResponse
    {
        try {
            DB::transaction(function () use ($group): void {
                $locked = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
                if ($locked->members()->exists() || $locked->cashEntries()->withTrashed()->exists()) {
                    throw ValidationException::withMessages(['group' => 'Kelompok masih memiliki anggota atau riwayat kas dan tidak dapat dihapus. Nonaktifkan kelompok bila diperlukan.']);
                }
                $locked->delete();
            }, 3);
        } catch (QueryException) {
            throw ValidationException::withMessages(['group' => 'Data terkait berubah saat penghapusan. Data tidak dihapus; muat ulang dan periksa relasinya.']);
        }

        return redirect()->route('admin.groups')->with('status', 'Kelompok tanpa relasi berhasil dihapus.');
    }

    public function deleteMember(Member $member): RedirectResponse
    {
        try {
            DB::transaction(function () use ($member): void {
                $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
                if ($locked->loans()->withTrashed()->exists() || $locked->savings()->withTrashed()->exists()) {
                    throw ValidationException::withMessages(['member' => 'Anggota memiliki riwayat transaksi dan tidak dapat dihapus. Nonaktifkan anggota agar riwayat tetap tersimpan.']);
                }
                $locked->delete();
            }, 3);
        } catch (QueryException) {
            throw ValidationException::withMessages(['member' => 'Data terkait berubah saat penghapusan. Data tidak dihapus; muat ulang dan periksa relasinya.']);
        }

        return redirect()->route('admin.members')->with('status', 'Anggota tanpa riwayat transaksi berhasil dihapus.');
    }
}
