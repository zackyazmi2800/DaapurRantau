<?php

namespace Tests\Support;

trait UnsplashFixture
{
    private function photo(string $description = 'Indonesian egg fried rice', string $id = 'test-photo'): array
    {
        return ['id' => $id, 'description' => $description, 'alt_description' => null,
            'urls' => ['small' => 'https://images.unsplash.com/photo-test?w=400&ixid=fixture', 'regular' => 'https://images.unsplash.com/photo-test?w=1080&ixid=fixture'],
            'user' => ['name' => 'Fotografer Uji', 'links' => ['html' => 'https://unsplash.com/@tester?existing=1']],
            'links' => ['html' => 'https://unsplash.com/photos/test-photo']];
    }
}
