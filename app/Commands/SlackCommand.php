<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class SlackCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'slack';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Clear statuspage messages from slack';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->channels()
            ->lazy()
            ->each(function (array $channel) {
                $lastRead = $this->lastReadAt($channel['id']);
                $messages = $this->messagesSince($channel['id'], $lastRead - 1);

                // starting at the first message
                $ts = $messages?->first()['ts'] ?? null;

                $messages->sliding(2)
                    ->eachSpread(function ($current, $next) use (&$ts) {
                        // if the current and next is a bot, set the marker as the next one and continue to next loop
                        if (($current['bot_id'] ?? null) == 'B8B2ESJ64' && ($next['bot_id'] ?? null) == 'B8B2ESJ64') {
                            $ts = $next['ts'];
                            return true;
                        }

                        // if the current is not a bot, stop here
                        if (($current['bot_id'] ?? null) != 'B8B2ESJ64') {
                            return false;
                        }

                        // if we got here, the next one must not be the bot, set the marker to the current and stop
                        $ts = $current['ts'];
                        return false;
                    });

                if ($ts != ($messages?->first()['ts'] ?? null)) {
                    $this->info('Clearing messages in ' . $channel['name']);
                    $this->setReadMarker($channel['id'], $ts);
                } else {
                    $this->info('No messages in ' . $channel['name']);
                }
            });

        return 0;
    }

    /**
     * Get a list of channels for the current slack user.
     *
     * @return Illuminate\Support\Collection
     */
    public function channels()
    {
        $response = Http::withToken(config('slack.token'))
            ->get('https://slack.com/api/users.conversations', [
                'types' => 'private_channel',
            ]);

        return $response->collect()
            ->pipe(function ($collection) {
                return collect($collection->get('channels'));
            });
    }

    /**
     * Get the timestamp for the last message that has been read in the given channel.
     *
     * @return string
     */
    public function lastReadAt(string $channelId)
    {
        $response = Http::withToken(config('slack.token'))
            ->get('https://slack.com/api/conversations.info', [
                'channel' => $channelId,
            ]);

        return $response->json('channel.last_read');
    }

    /**
     * Get all the messages since the given time in the channel
     * This returns the messages where the most recent is first
     *
     * @return Illuminate\Support\Collection
     */
    public function messagesSince(string $channelId, string $time)
    {
        $response = Http::withToken(config('slack.token'))
            ->get('https://slack.com/api/conversations.history', [
                'channel' => $channelId,
                'oldest' => $time,
            ]);

        return collect($response->json('messages'))->reverse();
    }

    /**
     * Set the read marker to the given time in the channel
     *
     * @return Illuminate\Support\Collection
     */
    public function setReadMarker(string $channelId, string $time)
    {
        $response = Http::withToken(config('slack.token'))
            ->get('https://slack.com/api/conversations.mark', [
                'channel' => $channelId,
                'ts' => $time,
            ]);

        return $response->collect();
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
