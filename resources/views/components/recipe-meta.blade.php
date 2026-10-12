@props(['recipe'])
<dl class="flex flex-wrap gap-x-5 gap-y-3 text-sm text-muted">
    <div class="flex items-center gap-1.5"><x-icon name="clock" class="size-4" /><dt class="sr-only">Waktu memasak</dt><dd>{{ $recipe['cook_time_minutes'] }} menit</dd></div>
    <div><dt class="sr-only">Jumlah porsi</dt><dd>{{ $recipe['servings'] }} porsi</dd></div>
    <div><dt class="sr-only">Kesulitan</dt><dd>{{ ucfirst($recipe['difficulty']) }}</dd></div>
</dl>
