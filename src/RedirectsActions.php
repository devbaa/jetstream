<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Symfony\Component\HttpFoundation\Response;

trait RedirectsActions
{
    /**
     * Get the redirect response for the given action.
     *
     * @param  object  $action
     * @return \Symfony\Component\HttpFoundation\Response
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
