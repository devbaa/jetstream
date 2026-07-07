<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Http\Response;

trait RedirectsActions
{
    /**
     * Get the redirect response for the given action.
     *
     * @param  object  $action
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function redirectPath(object $action)
    {
        if (method_exists($action, 'redirectTo')) {
            $response = $action->redirectTo();
        } else {
            $response = property_exists($action, 'redirectTo')
                                ? $action->redirectTo
                                : Jetstream::homePath();
        }

        if ($response instanceof Response) {
            return $response;
        }

        return redirect(is_string($response) ? $response : Jetstream::homePath());
    }
}
