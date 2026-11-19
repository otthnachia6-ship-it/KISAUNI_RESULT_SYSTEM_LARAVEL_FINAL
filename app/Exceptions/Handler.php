<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // "419 Page Expired" is confusing for teachers. When a form is sent after the
        // session has expired, send them somewhere sensible with a plain explanation.
        // (Laravel turns the TokenMismatchException into a 419 HttpException before these callbacks run.)
        $this->renderable(function (HttpException $e, Request $request) {
            if (!($e->getPrevious() instanceof TokenMismatchException) || $request->expectsJson()) {
                return null;
            }
            if ($request->routeIs('logout')) {
                return redirect()->route('login')->with('info', 'You have been logged out.');
            }

            return redirect()->back()
                ->withInput($request->except($this->dontFlash))
                ->with('warning', 'This page had expired, so nothing was saved. Please try again.');
        });
    }
}
