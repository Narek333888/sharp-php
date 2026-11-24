<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

/**
 * System.Web.IResult: what a route handler can return besides a string or an object for JSON.
 * Results.Json(...), Results.NotFound(), ... create them; a class of your own can implement it too.
 */
interface IResult
{
    /** result.ToResponse(): the HttpResponse to send; WebApp calls it on the IResult a route handler returns. */
    public function ToResponse(): HttpResponse;
}
