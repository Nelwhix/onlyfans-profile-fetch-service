<?php

use App\Domain\Profile\Support\CouldNotParseProfileResponse;
use App\Domain\Profile\ValueObjects\ParsedProfileResponse;
use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Symfony\Component\HttpFoundation\Response;

it('accepts an explicit likes value of zero', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 0, 'revision' => 1]);

    $parsed = ParsedProfileResponse::fromUpstreamResponse($response);

    expect($parsed->likes)->toBe(0);
});

it('rejects a response with a missing likes field', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['revision' => 1]);

    ParsedProfileResponse::fromUpstreamResponse($response);
})->throws(CouldNotParseProfileResponse::class, 'The likes value is missing, non-numeric, or negative: null');

it('rejects a negative likes value', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => -1, 'revision' => 1]);

    ParsedProfileResponse::fromUpstreamResponse($response);
})->throws(CouldNotParseProfileResponse::class, 'The likes value is missing, non-numeric, or negative: -1');

it('rejects a non-numeric likes value', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 'a lot', 'revision' => 1]);

    ParsedProfileResponse::fromUpstreamResponse($response);
})->throws(CouldNotParseProfileResponse::class, 'The likes value is missing, non-numeric, or negative: "a lot"');

it('rejects a response with a missing revision field', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 100]);

    ParsedProfileResponse::fromUpstreamResponse($response);
})->throws(CouldNotParseProfileResponse::class, 'The revision value is missing, non-numeric, or negative: null');

it('rejects a negative revision value', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 100, 'revision' => -1]);

    ParsedProfileResponse::fromUpstreamResponse($response);
})->throws(CouldNotParseProfileResponse::class, 'The revision value is missing, non-numeric, or negative: -1');

it('rejects a non-numeric revision value', function () {
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 100, 'revision' => 'latest']);

    ParsedProfileResponse::fromUpstreamResponse($response);
})->throws(CouldNotParseProfileResponse::class, 'The revision value is missing, non-numeric, or negative: "latest"');
