<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

trait InteractsWithBanner
{
    /**
     * Update the banner message.
     *
     * @param  string  $message
     * @param  string  $style
     * @return void
     */
    protected function banner($message, $style = 'success')
    {
        $this->dispatch('banner-message',
            style: $style,
            message: $message,
        );
    }

    /**
     * Update the banner message with a warning message.
     *
     * @param  string  $message
     * @return void
     */
    protected function warningBanner($message)
    {
        $this->banner($message, 'warning');
    }

    /**
     * Update the banner message with a danger / error message.
     *
     * @param  string  $message
     * @return void
     */
    protected function dangerBanner($message)
    {
        $this->banner($message, 'danger');
    }
}
