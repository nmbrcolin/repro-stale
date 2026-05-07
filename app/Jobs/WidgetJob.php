<?php

namespace App\Jobs;

use App\Models\Widget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;

#[Tries(5)]
#[UniqueFor(300)]
class WidgetJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected Widget $widget) {}

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->uniqueId())
                ->releaseAfter(30)
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
