<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The page content is wrapped in .page-enter. If that animation keeps its final transform
 * (animation-fill-mode "both" / "forwards"), every Bootstrap modal inside the page is trapped
 * under its own backdrop: the dialog opens, but its fields cannot be clicked or typed into.
 */
class ModalStackingTest extends TestCase
{
    public function test_page_enter_animation_does_not_keep_a_transform_after_it_ends(): void
    {
        $css = file_get_contents(public_path('css/style.css'));

        $this->assertSame(1, preg_match('/\.page-enter\s*\{\s*animation:([^;}]*)[;}]/', $css, $m), 'the .page-enter rule must exist');
        $this->assertStringNotContainsString('both', $m[1]);
        $this->assertStringNotContainsString('forwards', $m[1]);
    }
}
