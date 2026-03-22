(function($) {
    'use strict';

    // Poll a job until it completes
    function pollJob(jobId, callback) {
        var attempts = 0;
        var maxAttempts = 30;
        var interval = setInterval(function() {
            attempts++;
            $.post(twintConnect.ajaxUrl, {
                action: 'twint_poll_job',
                nonce: twintConnect.nonce,
                job_id: jobId
            }, function(response) {
                if (!response.success) {
                    clearInterval(interval);
                    callback({ error: response.data });
                    return;
                }
                var data = response.data;
                if (data.status === 'done') {
                    clearInterval(interval);
                    callback(data);
                } else if (data.status === 'error') {
                    clearInterval(interval);
                    callback({ error: data.error });
                } else if (attempts >= maxAttempts) {
                    clearInterval(interval);
                    callback({ error: 'Timeout: job took too long.' });
                }
            }).fail(function() {
                clearInterval(interval);
                callback({ error: 'Network error while polling.' });
            });
        }, 2000);
    }

    // Render tweets
    function renderTweets(tweets) {
        if (!tweets || tweets.length === 0) {
            return '<p>No tweets found.</p>';
        }
        var html = '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>User</th><th>Tweet</th><th>Date</th><th>Likes</th><th>RT</th></tr></thead><tbody>';
        tweets.forEach(function(t) {
            html += '<tr>';
            html += '<td><strong>' + escHtml(t.name) + '</strong><br>@' + escHtml(t.username) + '</td>';
            html += '<td>' + escHtml(t.tweet) + '</td>';
            html += '<td>' + escHtml(t.date) + '</td>';
            html += '<td>' + t.likes_count + '</td>';
            html += '<td>' + t.retweets_count + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '<p><strong>' + tweets.length + ' tweets found.</strong></p>';
        return html;
    }

    // Render user profile
    function renderUser(user) {
        var html = '<div class="twint-user-card">';
        if (user.avatar) {
            html += '<img src="' + escHtml(user.avatar) + '" class="twint-avatar" />';
        }
        html += '<h2>' + escHtml(user.name) + ' <small>@' + escHtml(user.username) + '</small></h2>';
        html += '<p>' + escHtml(user.bio) + '</p>';
        html += '<p><strong>Followers:</strong> ' + user.followers;
        html += ' &middot; <strong>Following:</strong> ' + user.following;
        html += ' &middot; <strong>Tweets:</strong> ' + user.tweets;
        html += ' &middot; <strong>Likes:</strong> ' + user.likes + '</p>';
        if (user.location) {
            html += '<p><strong>Location:</strong> ' + escHtml(user.location) + '</p>';
        }
        html += '<p><strong>Joined:</strong> ' + escHtml(user.join_date) + '</p>';
        html += '</div>';
        return html;
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setStatus(msg) {
        $('#twint-status').text(msg);
    }

    // Search button
    $(document).on('click', '#twint-do-search', function() {
        var $btn = $(this).prop('disabled', true);
        setStatus('Starting search...');
        $('#twint-results').html('');

        $.post(twintConnect.ajaxUrl, {
            action: 'twint_search',
            nonce: twintConnect.nonce,
            search: $('#twint-search').val(),
            username: $('#twint-username').val(),
            limit: $('#twint-limit').val(),
            lang: $('#twint-lang').val(),
            since: $('#twint-since').val(),
            until: $('#twint-until').val()
        }, function(response) {
            if (!response.success) {
                setStatus('Error: ' + response.data);
                $btn.prop('disabled', false);
                return;
            }
            setStatus('Searching... (polling for results)');
            pollJob(response.data.job_id, function(data) {
                $btn.prop('disabled', false);
                if (data.error) {
                    setStatus('Error: ' + data.error);
                    return;
                }
                setStatus('Done!');
                $('#twint-results').html(renderTweets(data.tweets));
            });
        }).fail(function() {
            setStatus('Network error.');
            $btn.prop('disabled', false);
        });
    });

    // Lookup button
    $(document).on('click', '#twint-do-lookup', function() {
        var username = $('#twint-username').val();
        if (!username) {
            setStatus('Please enter a username.');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        setStatus('Looking up user...');
        $('#twint-results').html('');

        $.post(twintConnect.ajaxUrl, {
            action: 'twint_lookup',
            nonce: twintConnect.nonce,
            username: username
        }, function(response) {
            if (!response.success) {
                setStatus('Error: ' + response.data);
                $btn.prop('disabled', false);
                return;
            }
            setStatus('Fetching... (polling for results)');
            pollJob(response.data.job_id, function(data) {
                $btn.prop('disabled', false);
                if (data.error) {
                    setStatus('Error: ' + data.error);
                    return;
                }
                setStatus('Done!');
                $('#twint-results').html(renderUser(data.user));
            });
        }).fail(function() {
            setStatus('Network error.');
            $btn.prop('disabled', false);
        });
    });

    // Test connection button
    $(document).on('click', '#twint-test-connection', function() {
        var $btn = $(this).prop('disabled', true);
        var $result = $('#twint-test-result').text('Testing...');

        // Quick health check via search endpoint proxy
        $.post(twintConnect.ajaxUrl, {
            action: 'twint_search',
            nonce: twintConnect.nonce,
            search: 'test',
            limit: 1
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $result.text('Connected successfully!').css('color', 'green');
            } else {
                $result.text('Failed: ' + response.data).css('color', 'red');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $result.text('Connection failed - network error.').css('color', 'red');
        });
    });

})(jQuery);
