"""
Twint REST API Server
Run on VPS to expose Twint functionality via HTTP endpoints.
WordPress plugin connects to this API remotely.
"""

import json
import threading
import uuid
import time
from flask import Flask, request, jsonify
from functools import wraps

import sys
import os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import twint
from twint.storage import panda

app = Flask(__name__)

# Simple API key auth - set via environment variable
API_KEY = os.environ.get('TWINT_API_KEY', 'change-me-in-production')

# Store async job results
jobs = {}


def require_api_key(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        key = request.headers.get('X-API-Key') or request.args.get('api_key')
        if not key or key != API_KEY:
            return jsonify({'error': 'Invalid or missing API key'}), 401
        return f(*args, **kwargs)
    return decorated


def run_twint_search(job_id, params):
    """Run a Twint search in a background thread."""
    try:
        c = twint.Config()
        c.Search = params.get('search', '')
        c.Username = params.get('username')
        c.Limit = params.get('limit', 20)
        c.Lang = params.get('lang')
        c.Since = params.get('since')
        c.Until = params.get('until')
        c.Min_likes = params.get('min_likes', 0)
        c.Min_retweets = params.get('min_retweets', 0)
        c.Hide_output = True
        c.Pandas = True
        c.Pandas_clean = True

        twint.run.Search(c)

        df = panda.Tweets_df
        if df is not None and not df.empty:
            tweets = df.to_dict('records')
            # Keep only serializable fields
            clean_tweets = []
            for t in tweets:
                clean_tweets.append({
                    'id': str(t.get('id', '')),
                    'date': str(t.get('date', '')),
                    'time': str(t.get('time', '')),
                    'username': str(t.get('username', '')),
                    'name': str(t.get('name', '')),
                    'tweet': str(t.get('tweet', '')),
                    'likes_count': int(t.get('likes_count', 0)),
                    'retweets_count': int(t.get('retweets_count', 0)),
                    'replies_count': int(t.get('replies_count', 0)),
                    'link': str(t.get('link', '')),
                    'hashtags': t.get('hashtags', []),
                    'photos': t.get('photos', []),
                    'video': int(t.get('video', 0)),
                })
            jobs[job_id] = {'status': 'done', 'tweets': clean_tweets, 'count': len(clean_tweets)}
        else:
            jobs[job_id] = {'status': 'done', 'tweets': [], 'count': 0}

    except Exception as e:
        jobs[job_id] = {'status': 'error', 'error': str(e)}


def run_twint_lookup(job_id, username):
    """Run a Twint user lookup in a background thread."""
    try:
        c = twint.Config()
        c.Username = username
        c.Hide_output = True
        c.Pandas = True
        c.Pandas_clean = True

        twint.run.Lookup(c)

        df = panda.User_df
        if df is not None and not df.empty:
            user = df.to_dict('records')[0]
            clean_user = {
                'id': str(user.get('id', '')),
                'name': str(user.get('name', '')),
                'username': str(user.get('username', '')),
                'bio': str(user.get('bio', '')),
                'followers': int(user.get('followers', 0)),
                'following': int(user.get('following', 0)),
                'tweets': int(user.get('tweets', 0)),
                'likes': int(user.get('likes', 0)),
                'avatar': str(user.get('avatar', '')),
                'join_date': str(user.get('join_date', '')),
                'location': str(user.get('location', '')),
                'url': str(user.get('url', '')),
                'verified': bool(user.get('verified', False)),
            }
            jobs[job_id] = {'status': 'done', 'user': clean_user}
        else:
            jobs[job_id] = {'status': 'error', 'error': 'User not found'}

    except Exception as e:
        jobs[job_id] = {'status': 'error', 'error': str(e)}


@app.route('/api/health', methods=['GET'])
def health():
    return jsonify({'status': 'ok', 'version': '1.0.0'})


@app.route('/api/search', methods=['POST'])
@require_api_key
def search_tweets():
    """Start a tweet search job. Returns a job_id to poll for results."""
    params = request.get_json() or {}
    if not params.get('search') and not params.get('username'):
        return jsonify({'error': 'search or username parameter required'}), 400

    job_id = str(uuid.uuid4())
    jobs[job_id] = {'status': 'running'}

    thread = threading.Thread(target=run_twint_search, args=(job_id, params))
    thread.daemon = True
    thread.start()

    return jsonify({'job_id': job_id, 'status': 'running'}), 202


@app.route('/api/lookup', methods=['POST'])
@require_api_key
def lookup_user():
    """Start a user lookup job."""
    params = request.get_json() or {}
    username = params.get('username')
    if not username:
        return jsonify({'error': 'username parameter required'}), 400

    job_id = str(uuid.uuid4())
    jobs[job_id] = {'status': 'running'}

    thread = threading.Thread(target=run_twint_lookup, args=(job_id, username))
    thread.daemon = True
    thread.start()

    return jsonify({'job_id': job_id, 'status': 'running'}), 202


@app.route('/api/job/<job_id>', methods=['GET'])
@require_api_key
def get_job(job_id):
    """Poll for job results."""
    if job_id not in jobs:
        return jsonify({'error': 'Job not found'}), 404
    return jsonify(jobs[job_id])


@app.route('/api/job/<job_id>', methods=['DELETE'])
@require_api_key
def delete_job(job_id):
    """Clean up a completed job."""
    if job_id in jobs:
        del jobs[job_id]
    return jsonify({'status': 'deleted'})


if __name__ == '__main__':
    port = int(os.environ.get('TWINT_API_PORT', 5000))
    debug = os.environ.get('TWINT_DEBUG', 'false').lower() == 'true'
    app.run(host='0.0.0.0', port=port, debug=debug)
