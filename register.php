<?php
// Keep one source for both authentication forms and their validation.
header('Cache-Control: no-store');
header('Location: index.html#register', true, 302);
exit();
