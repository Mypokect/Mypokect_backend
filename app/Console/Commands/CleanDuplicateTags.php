<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateTags extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tags:clean-duplicates
                            {--user= : Specific user ID to clean (optional)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean duplicate tags and remove goal tags from regular tags table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $userId = $this->option('user');

        $this->info('🧹 Starting tag cleanup...');
        $this->newLine();

        // Step 1: Remove goal tags (tags that start with "Meta:" or contain emoji)
        $this->info('📋 Step 1: Removing goal tags from regular tags table...');
        $goalTagsQuery = Tag::where(function ($query) {
            $query->where('name', 'LIKE', 'Meta:%')
                ->orWhere('name', 'LIKE', 'meta:%')
                ->orWhere('name', 'LIKE', '%💰%');
        });

        if ($userId) {
            $goalTagsQuery->where('user_id', $userId);
        }

        $goalTags = $goalTagsQuery->get();

        if ($goalTags->count() > 0) {
            $this->table(
                ['ID', 'User', 'Tag Name'],
                $goalTags->map(fn ($tag) => [$tag->id, $tag->user_id, $tag->name])
            );

            if ($isDryRun) {
                $this->warn("DRY RUN: Would delete {$goalTags->count()} goal tags");
            } else {
                $deleted = $goalTagsQuery->delete();
                $this->info("✅ Deleted {$deleted} goal tags");
            }
        } else {
            $this->info('✅ No goal tags found in regular tags table');
        }

        $this->newLine();

        // Step 2: Find and merge duplicates
        $this->info('📋 Step 2: Finding duplicate tags...');

        $duplicatesQuery = DB::table('tags')
            ->select('user_id', DB::raw('LOWER(name) as normalized_name'), DB::raw('COUNT(*) as count'))
            ->groupBy('user_id', DB::raw('LOWER(name)'))
            ->having('count', '>', 1);

        if ($userId) {
            $duplicatesQuery->where('user_id', $userId);
        }

        $duplicates = $duplicatesQuery->get();

        if ($duplicates->count() > 0) {
            $this->warn("Found {$duplicates->count()} sets of duplicate tags");

            foreach ($duplicates as $duplicate) {
                $tags = Tag::where('user_id', $duplicate->user_id)
                    ->whereRaw('LOWER(name) = ?', [$duplicate->normalized_name])
                    ->orderBy('id', 'asc')
                    ->get();

                $keepTag = $tags->first(); // Keep the oldest one
                $deleteIds = $tags->skip(1)->pluck('id')->toArray();

                $this->info("\nUser {$duplicate->user_id} - Tag: '{$keepTag->name}'");
                $this->line("  Keeping ID: {$keepTag->id}");
                $this->line('  Deleting IDs: '.implode(', ', $deleteIds));

                if (! $isDryRun) {
                    // Update references in movements table
                    DB::table('movements')
                        ->whereIn('tag_id', $deleteIds)
                        ->update(['tag_id' => $keepTag->id]);

                    // Update references in saving_goals table (if any still reference old tags)
                    DB::table('saving_goals')
                        ->whereIn('tag_id', $deleteIds)
                        ->update(['tag_id' => $keepTag->id]);

                    // Delete duplicate tags
                    Tag::whereIn('id', $deleteIds)->delete();

                    $this->info('  ✅ Merged and deleted '.count($deleteIds).' duplicates');
                } else {
                    $this->warn('  DRY RUN: Would merge and delete '.count($deleteIds).' duplicates');
                }
            }
        } else {
            $this->info('✅ No duplicate tags found');
        }

        $this->newLine();

        // Step 3: Summary
        $this->info('📊 Cleanup Summary:');
        $remainingTags = Tag::when($userId, fn ($q) => $q->where('user_id', $userId))->count();
        $this->info("Total tags remaining: {$remainingTags}");

        if ($isDryRun) {
            $this->newLine();
            $this->warn('⚠️  This was a DRY RUN. No changes were made.');
            $this->info('Run without --dry-run to actually clean the database:');
            $this->line('  php artisan tags:clean-duplicates');
        } else {
            $this->newLine();
            $this->info('✅ Tag cleanup completed successfully!');
        }

        return Command::SUCCESS;
    }
}
