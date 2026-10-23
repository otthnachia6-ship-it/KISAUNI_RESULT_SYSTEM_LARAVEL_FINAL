@extends('base')
@section('page_title', 'Register Multiple Students')
@section('content')
<x-page_header title="Register Multiple Students" subtitle="Add many students to a class in one go">
    <x-slot:actions>
        <a href="{{ route('add_student') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-person-plus me-1"></i>Register One Instead
        </a>
    </x-slot:actions>
</x-page_header>
<div class="card p-4">
    <p class="text-muted">Add as many students as you like below, then click <strong>Save All</strong> once at the end.</p>

    <form method="POST" action="{{ route('add_students_bulk') }}" id="bulkForm">
        @csrf
        <div class="mb-3" style="max-width:320px;">
            <label class="form-label">Class</label>
            @if($session_user->role === $ROLE_CLASS_TEACHER)
                <input type="text" class="form-control" value="{{ count($classes) > 0 ? $classes[0]->name : 'No class assigned' }}" disabled>
                <input type="hidden" name="class_id" value="{{ count($classes) > 0 ? $classes[0]->id : '' }}">
            @else
                <select class="form-select" name="class_id" required>
                    <option value="">-- Select Class --</option>
                    @foreach($classes as $c)
                    <option value="{{ $c->id }}" @if(strval($selected_class) === strval($c->id)) selected @endif>{{ $c->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="bulkTable">
                <thead>
                    <tr>
                        <th style="width:3%;">#</th>
                        <th style="width:22%;">ZEMS No</th>
                        <th style="width:45%;">Student's Full Name</th>
                        <th style="width:20%;">Gender</th>
                        <th style="width:10%;"></th>
                    </tr>
                </thead>
                <tbody id="bulkTableBody">
                    @forelse($rows as $r)
                    <tr>
                        <td class="row-num"></td>
                        <td><input type="text" class="form-control text-uppercase" name="zems_no[]" maxlength="32" autocomplete="off" placeholder="ZE0423951897" aria-label="ZEMS No" value="{{ $r['zems_no'] ?? '' }}"></td>
                        <td><input type="text" class="form-control bulk-name" name="full_name[]" value="{{ $r['full_name'] ?? '' }}"></td>
                        <td>
                            <select class="form-select bulk-gender" name="gender[]">
                                <option value="">-- Select --</option>
                                <option value="Male" @if(($r['gender'] ?? '') == 'Male') selected @endif>Male</option>
                                <option value="Female" @if(($r['gender'] ?? '') == 'Female') selected @endif>Female</option>
                            </select>
                        </td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    @empty
                    @for($i = 0; $i < 3; $i++)
                    <tr>
                        <td class="row-num"></td>
                        <td><input type="text" class="form-control text-uppercase" name="zems_no[]" maxlength="32" autocomplete="off" placeholder="ZE0423951897" aria-label="ZEMS No"></td>
                        <td><input type="text" class="form-control bulk-name" name="full_name[]"></td>
                        <td>
                            <select class="form-select bulk-gender" name="gender[]">
                                <option value="">-- Select --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    @endfor
                    @endforelse
                </tbody>
            </table>
        </div>

        <button type="button" id="addRowBtn" class="btn btn-outline-primary btn-sm mb-4">
            <i class="bi bi-plus-lg me-1"></i>Add Another Row
        </button>

        <div>
            <button type="submit" class="btn btn-success px-4"><i class="bi bi-save me-1"></i>Save All</button>
            <a href="{{ route('students') }}" class="btn btn-outline-secondary px-4">Cancel</a>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
(function () {
    const tbody = document.getElementById("bulkTableBody");
    const addRowBtn = document.getElementById("addRowBtn");

    function renumberRows() {
        tbody.querySelectorAll("tr").forEach((tr, i) => {
            tr.querySelector(".row-num").textContent = i + 1;
        });
    }

    function attachRowEvents(tr) {
        const removeBtn = tr.querySelector(".remove-row");
        removeBtn.addEventListener("click", function () {
            if (tbody.querySelectorAll("tr").length > 1) {
                tr.remove();
                renumberRows();
            } else {
                tr.querySelectorAll("input").forEach(i => i.value = "");
                tr.querySelectorAll("select").forEach(s => s.value = "");
            }
        });

        const nameInput = tr.querySelector(".bulk-name");
        const genderSelect = tr.querySelector(".bulk-gender");
        let debounceTimer;
        nameInput.addEventListener("input", function () {
            clearTimeout(debounceTimer);
            const name = nameInput.value.trim();
            debounceTimer = setTimeout(async () => {
                if (!name || genderSelect.value) return;
                try {
                    const res = await fetch(`/api/detect-gender?name=${encodeURIComponent(name)}`);
                    const data = await res.json();
                    if (data.gender) genderSelect.value = data.gender;
                } catch (e) {
                    console.error("Gender detect failed", e);
                }
            }, 450);
        });
    }

    function addRow() {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td class="row-num"></td>
            <td><input type="text" class="form-control text-uppercase" name="zems_no[]" maxlength="32" autocomplete="off" placeholder="ZE0423951897" aria-label="ZEMS No"></td>
            <td><input type="text" class="form-control bulk-name" name="full_name[]"></td>
            <td>
                <select class="form-select bulk-gender" name="gender[]">
                    <option value="">-- Select --</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        attachRowEvents(tr);
        renumberRows();
        tr.querySelector("input[name='zems_no[]']").focus();
    }

    tbody.querySelectorAll("tr").forEach(attachRowEvents);
    renumberRows();
    addRowBtn.addEventListener("click", addRow);
})();
</script>
@endsection
