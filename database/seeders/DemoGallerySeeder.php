<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoGallerySeeder extends Seeder
{
    public const AUTHOR_EMAIL = 'community-recipes@lessbuild.test';

    public function run(): void
    {
        $author = User::query()->updateOrCreate(
            ['email' => self::AUTHOR_EMAIL],
            [
                'name' => 'Lessbuild Community',
                'password' => Hash::make('disabled-community-fixture-account'),
                'password_set_at' => now(),
                'email_verified_at' => now(),
                'auth_type' => null,
            ],
        );

        $definitions = [
            [
                'name' => 'Harden SSH defaults',
                'description' => 'Disables password authentication and root login after confirming key-based access.',
                'category' => 'security',
                'script' => "sed -i 's/^#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config\nsed -i 's/^#PermitRootLogin prohibit-password/PermitRootLogin no/' /etc/ssh/sshd_config\nsshd -t\nsystemctl reload ssh",
                'install_count' => 18,
                'days_ago' => 12,
            ],
            [
                'name' => 'Install Node.js LTS',
                'description' => 'Installs the current Node.js LTS release from the official NodeSource repository.',
                'category' => 'runtime',
                'script' => "curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -\napt-get install -y nodejs\nnode --version\nnpm --version",
                'install_count' => 31,
                'days_ago' => 8,
            ],
            [
                'name' => 'Install unattended upgrades',
                'description' => 'Enables automatic installation of security updates on Debian and Ubuntu servers.',
                'category' => 'security',
                'script' => "apt-get update\nDEBIAN_FRONTEND=noninteractive apt-get install -y unattended-upgrades\ndpkg-reconfigure -f noninteractive unattended-upgrades",
                'install_count' => 24,
                'days_ago' => 3,
            ],
        ];

        $galleryRecipes = [];
        foreach ($definitions as $definition) {
            $galleryRecipes[$definition['name']] = $author->recipes()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'description' => $definition['description'],
                    'script' => $definition['script'],
                    'category' => $definition['category'],
                    'is_published' => true,
                    'published_at' => now()->subDays($definition['days_ago']),
                    'gallery_revision_at' => now()->subDays($definition['days_ago']),
                    'install_count' => $definition['install_count'],
                ],
            );
        }

        $source = $galleryRecipes['Harden SSH defaults'];
        $demoOwner = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();
        $importedRecipe = $demoOwner->recipes()
            ->where('source_recipe_id', $source->id)
            ->oldest('id')
            ->firstOrNew();
        $importedRecipe->fill([
            'name' => DemoSeeder::PREFIX.'Imported SSH hardening',
            'description' => 'An older private gallery snapshot with an update ready to review.',
            'script' => "echo 'Review SSH access before applying the current hardening recipe'",
            'source_recipe_id' => $source->id,
            'source_revision_at' => $source->gallery_revision_at?->copy()->subDay(),
            'category' => null,
            'is_published' => false,
            'published_at' => null,
            'gallery_revision_at' => null,
            'install_count' => 0,
        ])->save();

        $demoOwner->recipes()
            ->where('source_recipe_id', $source->id)
            ->whereKeyNot($importedRecipe->id)
            ->where('name', DemoSeeder::PREFIX.'Imported SSH hardening')
            ->delete();
    }
}
