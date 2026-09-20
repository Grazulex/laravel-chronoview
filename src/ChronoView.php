<?php

declare(strict_types=1);

namespace Grazulex\ChronoView;

use Closure;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Models\TaskRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class ChronoView
{
    private ?Closure $authUsing = null;

    public function auth(Closure $callback): static
    {
        $this->authUsing = $callback;

        return $this;
    }

    public function check(Request $request): bool
    {
        if ($this->authUsing !== null) {
            return (bool) ($this->authUsing)($request);
        }

        if (Gate::has('viewChronoView')) {
            return Gate::forUser($request->user())->check('viewChronoView');
        }

        $allowed = $this->allowedEmails();

        if ($allowed !== []) {
            $user = Auth::guard(config('chronoview.auth.guard'))->user();
            $email = is_object($user) && isset($user->email) ? mb_strtolower((string) $user->email) : null;

            return $email !== null && in_array($email, $allowed, true);
        }

        return app()->environment('local');
    }

    /**
     * @return array<int, string>
     */
    public function allowedEmails(): array
    {
        $raw = config('chronoview.auth.allowed_emails');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(
            fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            $list,
        )));
    }

    public function enabled(): bool
    {
        return (bool) config('chronoview.enabled', true);
    }

    public function path(): string
    {
        return trim((string) config('chronoview.path', 'chronoview'), '/');
    }

    public function refresh(): int
    {
        return max(0, (int) config('chronoview.refresh', 15));
    }

    /**
     * @return array{runs: int, success_rate: float|null, failed: int, missed: int, running: int, avg_duration_ms: int|null}
     */
    public function stats(int $hours = 24): array
    {
        $since = Carbon::now()->subHours($hours);
        $counts = TaskRun::query()->since($since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v): int => (int) $v);

        $success = $counts->get(RunStatus::Success->value, 0);
        $failed = $counts->get(RunStatus::Failed->value, 0);
        $missed = $counts->get(RunStatus::Missed->value, 0);
        $finished = $success + $failed;

        $avg = TaskRun::query()->since($since)
            ->whereIn('status', [RunStatus::Success->value, RunStatus::Failed->value])
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        return [
            'runs' => $success + $failed + $missed + $counts->get(RunStatus::Skipped->value, 0) + $counts->get(RunStatus::Running->value, 0),
            'success_rate' => $finished > 0 ? round($success / $finished * 100, 1) : null,
            'failed' => $failed,
            'missed' => $missed,
            'running' => TaskRun::query()->where('status', RunStatus::Running->value)->count(),
            'avg_duration_ms' => $avg !== null ? (int) round((float) $avg) : null,
        ];
    }
}
