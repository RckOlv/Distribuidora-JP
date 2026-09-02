<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Un visitante anónimo es redirigido al login.
     */
    public function test_raiz_redirige_a_login_para_invitados(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
