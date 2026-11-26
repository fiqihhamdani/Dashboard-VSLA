<div class="sm:col-span-2">
<label for="{{ $field }}">{{ $label }}</label>
<input type="url" id="{{ $field }}" name="{{ $field }}" maxlength="2048" value="{{ old($field, $value ?? '') }}" placeholder="https://drive.google.com/file/d/.../view" data-drive-url aria-describedby="{{ $field }}-help">
<p id="{{ $field }}-help" class="muted mt-2">Tempel URL file Google Drive. Atur izin akses file sesuai kebutuhan di Google Drive. Aplikasi tidak mengubah izin. Kosongkan untuk melepas tautan tanpa menghapus file.</p>
</div>
