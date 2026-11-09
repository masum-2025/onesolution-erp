<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-2: comments and Messenger in one inbox, with automatic replies (in the
 * client's database; organization_id is the company on every table).
 *
 * - social_pages gains: the permissions Facebook gave (scopes), when the page
 *   was subscribed to the app's webhook, and two switches: automatic replies
 *   on the page, and whether a price goes in the public comment reply (off:
 *   to the customer's inbox, with a short public notice).
 * - social_threads: one conversation: a customer's comments under one post
 *   (kind comment) or their Messenger chat with the page (kind message).
 *   The customer is Meta's id for them at this page; their name as Meta gave it.
 * - social_messages: every message in and out, append-only (who sent it:
 *   the customer, the bot or a person; what was understood; the products).
 * - social_reply_templates: what the bot understands (words per language)
 *   and answers, per company; changed on the Replies screen.
 * - social_webhook_events: Meta's ids already handled (Meta sends some twice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_pages', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('can_post');
            $table->timestamp('subscribed_at')->nullable()->after('scopes');
            $table->boolean('auto_reply')->default(true)->after('subscribed_at');
            $table->boolean('price_public')->default(true)->after('auto_reply');
        });

        Schema::create('social_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('page_id');
            // comment, message
            $table->string('kind', 10);
            // c:{fb post}:{customer} or m:{customer}
            $table->string('thread_key', 200);
            $table->string('customer_ref', 60);
            $table->string('customer_name', 150)->nullable();
            // Facebook's post the comments are under (comment threads).
            $table->string('fb_post_id', 80)->nullable();
            // open, waiting (for a person), done
            $table->string('status', 10);
            $table->ulid('assigned_to')->nullable();
            $table->unsignedSmallInteger('unknown_count')->default(0);
            $table->timestamp('last_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'page_id', 'thread_key']);
            $table->index(['organization_id', 'status', 'last_in_at']);
        });

        Schema::create('social_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('thread_id');
            // in, out
            $table->string('direction', 3);
            // customer, bot, person
            $table->string('sender', 10);
            $table->ulid('sender_user_id')->nullable();
            // public (a comment reply), private (to the inbox)
            $table->string('channel', 10);
            $table->text('text');
            // Facebook's id: the comment or message (in), what Facebook gave back (out).
            $table->string('fb_id', 120)->nullable();
            // What the bot understood (price, stock, delivery, order) or null.
            $table->string('intent', 20)->nullable();
            $table->string('locale', 10)->nullable();
            $table->json('item_ids')->nullable();
            // sent, failed (out)
            $table->string('status', 10)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['thread_id', 'created_at']);
            $table->unique(['organization_id', 'op_id']);
        });

        Schema::create('social_reply_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // price, stock, delivery, order, notice, handoff
            $table->string('intent', 20);
            // {"bn": ["dam", "দাম", "pp"], "en": ["price"]}: the language of the word is the language of the answer.
            $table->json('keywords');
            // {"bn": "...", "en": "..."} with {product} {price} {stock} {delivery} {page}
            $table->json('answers');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'intent']);
        });

        Schema::create('social_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('event_id', 150);
            $table->timestamp('created_at')->nullable();
            $table->unique(['organization_id', 'event_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_webhook_events');
        Schema::dropIfExists('social_reply_templates');
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_threads');
        Schema::table('social_pages', function (Blueprint $table) {
            $table->dropColumn(['scopes', 'subscribed_at', 'auto_reply', 'price_public']);
        });
    }
};
