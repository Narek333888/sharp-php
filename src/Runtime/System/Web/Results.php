<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

use SharpPhp\Runtime\System\Text\Json\JsonSerializer;
use SharpPhp\Runtime\System\Text\Json\JsonSerializerOptions;

/**
 * System.Web.Results: ready-made responses for route handlers, as in ASP.NET Core.
 *
 *   app.MapGet("/users/{id:int}", req =>
 *   {
 *       var user = users.Find(u => u.Id == int.Parse(req.Route["id"]!));
 *       return user is null ? Results.NotFound() : Results.Json(user);
 *   });
 *
 * Values are written as JSON with camelCase property names (ASP.NET Core's web defaults).
 */
final class Results
{
    public const string TEXT = 'text/plain; charset=utf-8';
    public const string HTML = 'text/html; charset=utf-8';
    public const string JSON = 'application/json; charset=utf-8';

    /** Plain text (text/plain unless another content type is given). */
    public static function Text(string $content, ?string $contentType = null, ?int $statusCode = null): HttpResponse
    {
        return new HttpResponse($statusCode ?? 200, $content, $contentType ?? self::TEXT);
    }

    /** Content of any type: Results.Content("<svg>...</svg>", "image/svg+xml"). */
    public static function Content(string $content, ?string $contentType = null, ?int $statusCode = null): HttpResponse
    {
        return self::Text($content, $contentType, $statusCode);
    }

    /** An HTML page. Encode text you put into it with WebUtility.HtmlEncode(). */
    public static function Html(string $html, ?int $statusCode = null): HttpResponse
    {
        return new HttpResponse($statusCode ?? 200, $html, self::HTML);
    }

    /** The value as JSON: objects, anonymous objects, lists, dictionaries, numbers, strings. */
    public static function Json(mixed $data, ?int $statusCode = null): HttpResponse
    {
        return new HttpResponse($statusCode ?? 200, JsonSerializer::Serialize($data, JsonSerializerOptions::Web()), self::JSON);
    }

    /** 200 OK, with the value as JSON when one is given. */
    public static function Ok(mixed $value = null): HttpResponse
    {
        return self::withValue(200, $value);
    }

    /** 201 Created with a Location header, and the created value as JSON when one is given. */
    public static function Created(string $uri, mixed $value = null): HttpResponse
    {
        return self::withValue(201, $value)->WithHeader('Location', $uri);
    }

    /** 204 No Content. */
    public static function NoContent(): HttpResponse
    {
        return new HttpResponse(204);
    }

    /** 400 Bad Request, with the error as JSON when one is given. */
    public static function BadRequest(mixed $error = null): HttpResponse
    {
        return self::withValue(400, $error);
    }

    /** 401 Unauthorized. */
    public static function Unauthorized(): HttpResponse
    {
        return new HttpResponse(401);
    }

    /** 403 Forbidden. */
    public static function Forbid(): HttpResponse
    {
        return new HttpResponse(403);
    }

    /** 404 Not Found, with the value as JSON when one is given. */
    public static function NotFound(mixed $value = null): HttpResponse
    {
        return self::withValue(404, $value);
    }

    /** 409 Conflict, with the error as JSON when one is given. */
    public static function Conflict(mixed $error = null): HttpResponse
    {
        return self::withValue(409, $error);
    }

    /** An empty response with this status code. */
    public static function StatusCode(int $statusCode): HttpResponse
    {
        return new HttpResponse($statusCode);
    }

    /** 302 Found (or 301 Moved Permanently) to another address. */
    public static function Redirect(string $url, bool $permanent = false): HttpResponse
    {
        return (new HttpResponse($permanent ? 301 : 302))->WithHeader('Location', $url);
    }

    private static function withValue(int $statusCode, mixed $value): HttpResponse
    {
        return $value === null ? new HttpResponse($statusCode) : self::Json($value, $statusCode);
    }
}
