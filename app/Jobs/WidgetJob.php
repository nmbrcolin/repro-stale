<?php

namespace App\Jobs;

use App\Models\Widget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;

class WidgetJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public int $tries = 5;

    public function __construct(protected Widget $widget) {}

    public function middleware(): array
    {
        $backoff = array_map(
            fn($delay) => (int) ($delay * (1 + rand(-25, 25) / 100)),
            array_pad([1, 5, 15, 30], $this->tries ?? 5, 30),
        );

        $delay = $backoff[min($this->attempts() - 1, count($backoff) - 1)];

        return [
            new WithoutOverlapping($this->uniqueId())
                ->releaseAfter($delay)
                ->expireAfter(30),
        ];
    }

    public function uniqueId(): string
    {
        return $this->widget->id;
    }

    public function handle(): void
    {
        // Pretend to do some work on the widget.
        Sleep::for(random_int(100, 10_000))->milliseconds();

        // Un-stale the widget.
        $this->widget->update(['stale_since' => null]);
    }
}
