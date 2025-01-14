<?php

use Gazelle\Util\Irc;

/* Creating a new thread
 *   Form variables:
 *      $_POST['forum']
 *      $_POST['title']
 *      $_POST['body']
 *    optional for a poll:
 *      $_POST['question']
 *      $_POST['answers'] (array of answers)
 */

if ($Viewer->disablePosting()) {
    error('Your posting privileges have been removed.');
}
authorize();


if (isset($_POST['forum'])) {
    $forum = (new Gazelle\Manager\Forum)->findById((int)$_POST['forum']);
    if (is_null($forum)) {
        error(404);
    }
    $ForumID = $forum->id();
    if (!$Viewer->writeAccess($forum) || !$Viewer->createAccess($forum)) {
        error(403);
    }
}

// If you're not sending anything, go back
if (empty($_POST['body']) || empty($_POST['title'])) {
    header('Location: ' . redirectUrl("forums.php?action=viewforum&forumid={$_POST['forum']}"));
    exit;
}
$Title = shortenString(trim($_POST['title']), 150, true, false);
$Body = trim($_POST['body']);

if (empty($_POST['question']) || empty($_POST['answers']) || !$Viewer->permitted('forums_polls_create')) {
    $needPoll = false;
} else {
    $needPoll = true;
    $Question = trim($_POST['question']);
    $Answers = [];
    $Votes = [];

    // Step over empty answer fields to avoid gaps in the answer IDs
    foreach ($_POST['answers'] as $i => $Answer) {
        if ($Answer == '') {
            continue;
        }
        $Answers[$i + 1] = $Answer;
        $Votes[$i + 1] = 0;
    }

    if (count($Answers) < 2) {
        error('You cannot create a poll with only one answer.');
    } elseif (count($Answers) > 25) {
        error('You cannot create a poll with greater than 25 answers.');
    }
}

$threadId = $forum->addThread($Viewer->id(), $Title, $Body);
if ($needPoll) {
    $forum->addPoll($threadId, $Question, $Answers, $Votes);
    if ($ForumID == STAFF_FORUM_ID) {
        Irc::sendRaw('PRIVMSG '.MOD_CHAN.' :Poll created by '.$Viewer->username().": \"$Question\" ".SITE_URL."/forums.php?action=viewthread&threadid=$threadId");
    }
}

if (isset($_POST['subscribe'])) {
    (new Gazelle\Subscription($Viewer))->subscribe($threadId);
}

header("Location: forums.php?action=viewthread&threadid=$threadId");
