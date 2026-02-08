<?php

use App\Models\User;

describe('Authentication', function () {

    describe('POST /api/register', function () {

        it('registers a new user', function () {
            $response = $this->postJson('/api/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $response->assertStatus(201)
                ->assertJsonStructure([
                    'message',
                    'user' => ['id', 'name', 'email'],
                    'token',
                ]);

            $this->assertDatabaseHas('users', [
                'email' => 'test@example.com',
            ]);
        });

        it('requires valid email', function () {
            $response = $this->postJson('/api/register', [
                'name' => 'Test User',
                'email' => 'invalid-email',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

        it('requires password confirmation', function () {
            $response = $this->postJson('/api/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password123',
                'password_confirmation' => 'different',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        });

        it('requires unique email', function () {
            User::factory()->create(['email' => 'test@example.com']);

            $response = $this->postJson('/api/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

    });

    describe('POST /api/login', function () {

        it('logs in with valid credentials', function () {
            $user = User::factory()->create([
                'password' => bcrypt('password123'),
            ]);

            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'message',
                    'user',
                    'token',
                ]);
        });

        it('rejects invalid credentials', function () {
            $user = User::factory()->create([
                'password' => bcrypt('password123'),
            ]);

            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

    });

    describe('POST /api/logout', function () {

        it('logs out authenticated user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->postJson('/api/logout');

            $response->assertStatus(200)
                ->assertJson(['message' => 'Sesión cerrada exitosamente']);
        });

        it('requires authentication', function () {
            $response = $this->postJson('/api/logout');

            $response->assertStatus(401);
        });

    });

    describe('GET /api/me', function () {

        it('returns authenticated user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->getJson('/api/me');

            $response->assertStatus(200)
                ->assertJson([
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                    ],
                ]);
        });

        it('requires authentication', function () {
            $response = $this->getJson('/api/me');

            $response->assertStatus(401);
        });

    });

});
