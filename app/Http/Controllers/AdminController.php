<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMembersRequest;
use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function saveGroup(Request $request, ?Group $group = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'code' => ['required', 'string', 'max:40', Rule::unique('groups', 'code')->ignore($group?->id)], 'location' => 'required|string|max:255', 'is_active' => 'required|boolean'], [], ['name' => 'nama kelompok', 'code' => 'kode kelompok', 'location' => 'lokasi', 'is_active' => 'status']);
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
        $loans = Loan::with(['member.group', 'installments' => fn ($q) => $q->withSum('payments', 'amount')->with(['payments' => fn ($p) => $p->orderBy('paid_at')->orderBy('id')])->orderBy('sequence')])->when($data['group_id'] ?? null, fn ($q, $id) => $q->whereHas('member', fn ($m) => $m->where('group_id', $id)))->when($data['q'] ?? null, fn ($q, $name) => $q->whereHas('member', fn ($m) => $m->where('name', 'like', "%$name%")))->orderByDesc('disbursed_at')->orderByDesc('id')->paginate(15)->withQueryString();

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

    public function saveMember(Request $request, ?Member $member = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'group_id' => 'required|integer|exists:groups,id', 'is_active' => 'required|boolean'], [], ['name' => 'nama anggota', 'group_id' => 'kelompok', 'is_active' => 'status']);
        DB::transaction(function () use ($member, $data) {
            if ($member) {
                $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
                if ($locked->group_id !== (int) $data['group_id'] && ($locked->savings()->exists() || $locked->loans()->exists())) {
                    throw ValidationException::withMessages(['group_id' => 'Anggota yang memiliki riwayat transaksi tidak dapat dipindahkan ke kelompok lain. Nonaktifkan anggota bila diperlukan.']);
                }
                $locked->update($data);
            } else {
                Member::create($data);
            }
        }, 3);

        return redirect()->route('admin.members')->with('status', 'Data anggota berhasil disimpan. Riwayat transaksi tetap tersimpan.');
    }
}
