<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-1: Facebook pages and posts (in the client's database).
 *
 * organization_id is the company on every table. meta_app_id names the
 * platform's Meta app a page signed in through (an id only, no key).
 *
 * - social_pages: a connected Facebook page. The page token is encrypted;
 *   meta_person_id is who signed in (Meta's id for them in that app), so a
 *   "person removed the app" callback finds their pages. Disconnecting
 *   forgets the token and keeps the row (posts point at it).
 * - social_posts: a post written in the ERP: text, language, when it goes
 *   out, where it stands (draft → pending → scheduled → publishing →
 *   published, or rejected / failed) and what Facebook gave back.
 * - social_post_media: its photos (private files), in order.
 * - social_post_products: the products it shows, with the price at the
 *   time; comment replies (SC-2) find the product of a post here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('meta_app_id');
            $table->string('page_id', 40);
            $table->string('name', 150);
            $table->string('picture_url', 1000)->nullable();
            $table->text('token')->nullable();
            $table->string('meta_person_id', 40)->nullable();
            // Whether the person may create posts on the page (Facebook task CREATE_CONTENT).
            $table->boolean('can_post')->default(false);
            // connected, needs_reconnect, disconnected
            $table->string('status', 20);
            $table->string('status_reason', 40)->nullable();
            $table->ulid('connected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'page_id']);
            $table->index(['page_id', 'status']);
            $table->index(['meta_app_id', 'meta_person_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('page_id');
            $table->text('message')->nullable();
            // The post's language (its footer line comes in it).
            $table->string('locale', 10);
            // draft, pending, scheduled, publishing, published, rejected, failed
            $table->string('status', 20);
            // When it goes out (UTC); empty: as soon as it is approved.
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('fb_post_id', 80)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 300)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->ulid('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'status', 'publish_at']);
            $table->index('fb_post_id');
        });

        Schema::create('social_post_media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('post_id');
            $table->unsignedSmallInteger('position');
            $table->string('path', 300);
            $table->string('mime', 40);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            // Facebook's id once uploaded (a retry does not upload it twice).
            $table->string('fb_photo_id', 80)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['post_id', 'position']);
        });

        Schema::create('social_post_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('post_id');
            // Inventory's item (an id only).
            $table->ulid('item_id');
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency_code', 3);
            $table->timestamp('created_at')->nullable();
            $table->unique(['post_id', 'item_id']);
            $table->index(['organization_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_products');
        Schema::dropIfExists('social_post_media');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_pages');
    }
};
