<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page sends visitors to the dubbing page.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect('/dub');
    }
}
