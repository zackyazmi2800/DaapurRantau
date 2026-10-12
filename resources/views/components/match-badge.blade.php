@props(['match'])
<span @class(['inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-bold', 'bg-sage text-primary' => $match['ready'], 'bg-cream text-ink' => ! $match['ready']])><x-icon :name="$match['ready'] ? 'check' : 'plus'" class="size-4 shrink-0" />{{ $match['category'] }}</span>
