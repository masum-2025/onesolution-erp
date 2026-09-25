<?php

it('responds on the health endpoint', function () {
    $this->get('/up')->assertOk();
});
