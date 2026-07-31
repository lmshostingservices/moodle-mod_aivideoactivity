/**
 * AI Video Activity - Main JavaScript Module
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    return {
        init: function(cfg) {
            var config = cfg;
            var currentJobId = null;
            var statusPollingInterval = null;
            var statusPollFailures = 0;
            var MAX_POLL_FAILURES = 15;
            var quizData = null;
            var currentQuestionIndex = 0;
            var score = 0;
            // FIX-VA-SCORE-OVERCOUNT (v1.0.107): per-question scored guard. Without
            // this guard, any score++ that fires twice for the same question (race
            // condition double-clicks on Check, double Try-Again paths, double
            // listeners attached to a re-rendered Continue button, etc.) would
            // push the displayed score above the question total, producing the
            // impossible "10 / 9 = 111%" results screen reported on Mum's quiz.
            // Records which question indices have already been scored on this
            // attempt and refuses any further score++ for that index, regardless
            // of which question type's handler is firing.
            var scoredQuestionIndices = {};
            function tryScoreCurrentQuestion() {
                if (scoredQuestionIndices[currentQuestionIndex]) {
                    return false;
                }
                scoredQuestionIndices[currentQuestionIndex] = true;
                score++;
                return true;
            }
            var selectedAnswer = null;
            var selectedLabel = null;   // Letter label (A/B/C/D) of currently selected MCQ option.
            var audioElement = null;
            var audioContext = null;
            var currentAttemptId = null;
            var vaSelectedJobLevels = [];  // Multi-select job levels (pill buttons)
            var vaSelectedJobRoles  = [];  // Multi-select job roles (chips input)
            var regenerationCount = 0;
            var originalQuizData = null;

            var ytPlayer = null;
            var watchedSeconds = 0;
            var maxWatchedPosition = 0;
            var watchTimerInterval = null;
            var videoReady = false;
            var watchRequirementMet = false;

            var VA_ICONS = {
                shield: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
                book: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg>',
                lightbulb: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>',
                gear: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
                heart: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
                star: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
                check: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>',
                warning: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
                clock: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                target: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
                users: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                tool: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
                flag: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
                eye: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>',
                lock: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
            };

            function getVAIcon(name) {
                return VA_ICONS[name] || VA_ICONS.star;
            }

            // ==========================================
            // AJAX HELPER
            // ==========================================

            function ajaxCall(action, params, timeout) {
                var data = $.extend({
                    action: action,
                    sesskey: config.sesskey,
                    cmid: config.cmid
                }, params || {});

                var opts = {
                    url: config.wwwroot + '/mod/aivideoactivity/ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: data
                };
                if (timeout) {
                    opts.timeout = timeout;
                }
                return $.ajax(opts);
            }

            // ==========================================
            // UTILITY FUNCTIONS
            // ==========================================

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(text));
                return div.innerHTML;
            }

            function shuffleArray(arr) {
                var a = arr.slice();
                for (var i = a.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var temp = a[i];
                    a[i] = a[j];
                    a[j] = temp;
                }
                return a;
            }

            function arraysEqual(a, b) {
                if (a.length !== b.length) return false;
                for (var i = 0; i < a.length; i++) {
                    if (a[i] !== b[i]) return false;
                }
                return true;
            }

            function extractVideoId(url) {
                if (!url) return null;
                var patterns = [
                    /(?:youtube\.com\/watch\?v=|youtube\.com\/watch\?.+&v=)([a-zA-Z0-9_-]{11})/,
                    /youtu\.be\/([a-zA-Z0-9_-]{11})/,
                    /youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/
                ];
                for (var i = 0; i < patterns.length; i++) {
                    var match = url.match(patterns[i]);
                    if (match) return match[1];
                }
                return null;
            }

            // ==========================================
            // AUDIO FUNCTIONS
            // ==========================================

            function getAudioContext() {
                if (!audioContext) {
                    audioContext = new (window.AudioContext || window.webkitAudioContext)();
                }
                return audioContext;
            }

            function playCorrectSound() {
                // FIX-VA-AUDIO-RESUME: Resume suspended AudioContext (browser autoplay policy in iframes)
                // before scheduling oscillator. ctx.resume() resolves immediately if already running.
                // FIX-VA-AUDIO-NODECOUNT: Disconnect oscillator+gainNode onended to prevent Web Audio API
                // node accumulation. Chrome limits ~100 connected nodes per AudioContext; hitting the limit
                // silently drops all subsequent sounds (typically after question 3-4).
                try {
                    var ctx = getAudioContext();
                    ctx.resume().then(function() {
                        var oscillator = ctx.createOscillator();
                        var gainNode = ctx.createGain();
                        oscillator.connect(gainNode);
                        gainNode.connect(ctx.destination);
                        oscillator.frequency.setValueAtTime(880, ctx.currentTime);
                        oscillator.frequency.setValueAtTime(1108.73, ctx.currentTime + 0.1);
                        oscillator.type = 'sine';
                        gainNode.gain.setValueAtTime(0.3, ctx.currentTime);
                        gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                        oscillator.onended = function() { try { oscillator.disconnect(); gainNode.disconnect(); } catch(e) {} };
                        oscillator.start(ctx.currentTime);
                        oscillator.stop(ctx.currentTime + 0.3);
                    }).catch(function(e) { console.log('[VA] Audio resume failed:', e); });
                } catch (e) {
                    console.log('[VA] Audio not supported:', e);
                }
            }

            function playIncorrectSound() {
                // FIX-VA-AUDIO-RESUME: Resume suspended AudioContext before scheduling oscillator.
                // FIX-VA-AUDIO-NODECOUNT: Disconnect nodes onended to prevent accumulation.
                try {
                    var ctx = getAudioContext();
                    ctx.resume().then(function() {
                        var oscillator = ctx.createOscillator();
                        var gainNode = ctx.createGain();
                        oscillator.connect(gainNode);
                        gainNode.connect(ctx.destination);
                        oscillator.frequency.setValueAtTime(200, ctx.currentTime);
                        oscillator.frequency.setValueAtTime(150, ctx.currentTime + 0.1);
                        oscillator.type = 'sawtooth';
                        gainNode.gain.setValueAtTime(0.2, ctx.currentTime);
                        gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
                        oscillator.onended = function() { try { oscillator.disconnect(); gainNode.disconnect(); } catch(e) {} };
                        oscillator.start(ctx.currentTime);
                        oscillator.stop(ctx.currentTime + 0.25);
                    }).catch(function(e) { console.log('[VA] Audio resume failed:', e); });
                } catch (e) {
                    console.log('[VA] Audio not supported:', e);
                }
            }

            function playLevelCompleteSound() {
                // FIX-VA-AUDIO-RESUME: Resume suspended AudioContext before scheduling notes.
                // FIX-VA-AUDIO-NODECOUNT: Disconnect nodes onended to prevent accumulation.
                try {
                    var ctx = getAudioContext();
                    ctx.resume().then(function() {
                        var notes = [523.25, 659.25, 783.99, 1046.50];
                        var delay = 0;
                        notes.forEach(function(freq) {
                            var oscillator = ctx.createOscillator();
                            var gainNode = ctx.createGain();
                            oscillator.connect(gainNode);
                            gainNode.connect(ctx.destination);
                            oscillator.frequency.setValueAtTime(freq, ctx.currentTime + delay);
                            oscillator.type = 'sine';
                            gainNode.gain.setValueAtTime(0, ctx.currentTime + delay);
                            gainNode.gain.linearRampToValueAtTime(0.3, ctx.currentTime + delay + 0.05);
                            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + delay + 0.4);
                            oscillator.onended = function() { try { oscillator.disconnect(); gainNode.disconnect(); } catch(e) {} };
                            oscillator.start(ctx.currentTime + delay);
                            oscillator.stop(ctx.currentTime + delay + 0.4);
                            delay += 0.15;
                        });
                        setTimeout(function() {
                            [523.25, 659.25, 783.99, 1046.50].forEach(function(freq) {
                                var osc = ctx.createOscillator();
                                var gain = ctx.createGain();
                                osc.connect(gain);
                                gain.connect(ctx.destination);
                                osc.frequency.setValueAtTime(freq, ctx.currentTime);
                                osc.type = 'sine';
                                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.8);
                                osc.onended = function() { try { osc.disconnect(); gain.disconnect(); } catch(e) {} };
                                osc.start(ctx.currentTime);
                                osc.stop(ctx.currentTime + 0.8);
                            });
                        }, 700);
                    }).catch(function(e) { console.log('[VA] Audio resume failed:', e); });
                } catch (e) {
                    console.log('[VA] Audio not supported:', e);
                }
            }

            function playTryAgainSound() {
                // FIX-VA-AUDIO-RESUME: Resume suspended AudioContext before scheduling notes.
                // FIX-VA-AUDIO-NODECOUNT: Disconnect nodes onended to prevent accumulation.
                try {
                    var ctx = getAudioContext();
                    ctx.resume().then(function() {
                        var notes = [493.88, 440, 392, 349.23];
                        var delay = 0;
                        notes.forEach(function(freq) {
                            var oscillator = ctx.createOscillator();
                            var gainNode = ctx.createGain();
                            oscillator.connect(gainNode);
                            gainNode.connect(ctx.destination);
                            oscillator.frequency.setValueAtTime(freq, ctx.currentTime + delay);
                            oscillator.type = 'sine';
                            gainNode.gain.setValueAtTime(0, ctx.currentTime + delay);
                            gainNode.gain.linearRampToValueAtTime(0.2, ctx.currentTime + delay + 0.05);
                            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + delay + 0.3);
                            oscillator.onended = function() { try { oscillator.disconnect(); gainNode.disconnect(); } catch(e) {} };
                            oscillator.start(ctx.currentTime + delay);
                            oscillator.stop(ctx.currentTime + delay + 0.3);
                            delay += 0.2;
                        });
                    }).catch(function(e) { console.log('[VA] Audio resume failed:', e); });
                } catch (e) {
                    console.log('[VA] Audio not supported:', e);
                }
            }

            // ==========================================
            // CONFETTI ANIMATION
            // ==========================================

            function createConfetti() {
                var container = document.createElement('div');
                container.className = 'va-confetti-container';
                container.id = 'va-confetti';
                document.body.appendChild(container);

                var colors = ['#667eea', '#764ba2', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'];
                var confettiCount = 150;

                for (var i = 0; i < confettiCount; i++) {
                    var confetti = document.createElement('div');
                    confetti.className = 'va-confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                    confetti.style.animationDelay = Math.random() * 3 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';

                    if (Math.random() > 0.5) {
                        confetti.style.borderRadius = '50%';
                    }

                    container.appendChild(confetti);
                }

                setTimeout(function() {
                    if (container.parentNode) {
                        container.parentNode.removeChild(container);
                    }
                }, 5000);
            }

            // ==========================================
            // YOUTUBE IFRAME API
            // ==========================================

            function loadYouTubeAPI(callback) {
                if (window.YT && window.YT.Player) {
                    callback();
                    return;
                }

                window.onYouTubeIframeAPIReady = function() {
                    console.log('[VA] YouTube IFrame API ready');
                    callback();
                };

                var tag = document.createElement('script');
                tag.src = 'https://www.youtube.com/iframe_api';
                var firstScriptTag = document.getElementsByTagName('script')[0];
                firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);
            }

            function createYouTubePlayer(containerId, videoId) {
                console.log('[VA] Creating YouTube player for video:', videoId);

                ytPlayer = new YT.Player(containerId, {
                    videoId: videoId,
                    playerVars: {
                        autoplay: 0,
                        rel: 0,
                        modestbranding: 1
                    },
                    events: {
                        onReady: onPlayerReady,
                        onStateChange: onPlayerStateChange
                    }
                });
            }

            function onPlayerReady() {
                console.log('[VA] YouTube player ready');
                videoReady = true;

                if (config.watchMode === 'none') {
                    watchRequirementMet = true;
                    enableQuizButton();
                    updateWatchProgress();
                }
            }

            function onPlayerStateChange(event) {
                var state = event.data;
                console.log('[VA] Player state changed:', state);

                if (config.watchMode === 'none') return;

                // Start/stop the watch timer for seek-prevention and time-counting
                // for both 'all' and 'seconds' modes.
                if (state === YT.PlayerState.PLAYING) {
                    startWatchTimer();
                } else if (state === YT.PlayerState.PAUSED || state === YT.PlayerState.ENDED) {
                    stopWatchTimer();
                }

                if (state === YT.PlayerState.ENDED) {
                    if (config.watchMode === 'all') {
                        watchRequirementMet = true;
                        enableQuizButton();
                        updateWatchProgress();
                    } else if (config.watchMode === 'seconds') {
                        var duration = ytPlayer.getDuration ? ytPlayer.getDuration() : 0;
                        if (duration > 0) {
                            watchedSeconds = Math.max(watchedSeconds, Math.floor(duration));
                        }
                        checkWatchRequirement();
                    }
                }
            }

            function startWatchTimer() {
                if (watchTimerInterval) return;
                console.log('[VA] Starting watch timer');

                watchTimerInterval = setInterval(function() {
                    if (!ytPlayer || !ytPlayer.getCurrentTime) return;
                    var currentTime = ytPlayer.getCurrentTime();

                    // Seek prevention: if student jumps ahead of their furthest
                    // watched position by more than 2 seconds, snap them back.
                    if (!watchRequirementMet && currentTime > maxWatchedPosition + 2) {
                        console.log('[VA] Seek-ahead detected  -  snapping back to', maxWatchedPosition);
                        ytPlayer.seekTo(maxWatchedPosition, true);
                        return;
                    }

                    // Advance the high-water mark.
                    maxWatchedPosition = Math.max(maxWatchedPosition, currentTime);

                    if (config.watchMode === 'seconds') {
                        watchedSeconds++;
                        updateWatchProgress();
                        checkWatchRequirement();
                    }
                }, 1000);
            }

            function stopWatchTimer() {
                if (watchTimerInterval) {
                    clearInterval(watchTimerInterval);
                    watchTimerInterval = null;
                    console.log('[VA] Watch timer stopped at', watchedSeconds, 'seconds');
                }
            }

            function checkWatchRequirement() {
                if (watchRequirementMet) return;

                var required = parseInt(config.watchSeconds, 10) || 0;
                if (watchedSeconds >= required) {
                    watchRequirementMet = true;
                    enableQuizButton();
                    stopWatchTimer();
                }
                updateWatchProgress();
            }

            function updateWatchProgress() {
                var progressBar = document.getElementById('va-watch-progress-fill');
                var progressText = document.getElementById('va-watch-progress-text');

                if (!progressBar || !progressText) return;

                var isAudio = (config.mediaType === 'audio');

                if (config.watchMode === 'none') {
                    progressBar.style.width = '100%';
                    progressText.textContent = 'No ' + (isAudio ? 'listen' : 'watch') + ' requirement - quiz available now';
                } else if (config.watchMode === 'all') {
                    if (watchRequirementMet) {
                        progressBar.style.width = '100%';
                        progressText.textContent = (isAudio ? 'Audio' : 'Video') + ' completed - quiz unlocked!';
                    } else {
                        progressBar.style.width = '0%';
                        progressText.textContent = (isAudio ? 'Listen to the entire audio' : 'Watch the entire video') + ' to unlock the quiz';
                    }
                } else if (config.watchMode === 'seconds') {
                    var required = parseInt(config.watchSeconds, 10) || 0;
                    var pct = required > 0 ? Math.min(100, Math.floor((watchedSeconds / required) * 100)) : 100;
                    progressBar.style.width = pct + '%';

                    if (watchRequirementMet) {
                        progressText.textContent = (isAudio ? 'Listen' : 'Watch') + ' requirement met - quiz unlocked!';
                    } else {
                        progressText.textContent = (isAudio ? 'Listened' : 'Watched') + ': ' + watchedSeconds + ' of ' + required + ' seconds';
                    }
                }
            }

            // FIX-VA-RETAKE-LOADING-BTN (v1.0.109): SVG markup for the play-triangle icon
            // used inside both va-start-quiz-btn and va-continue-attempt-btn. handleStartAttempt
            // and handleContinueAttempt set `btn.textContent = 'Loading...'` while the AJAX
            // round-trip is in flight, which clobbers the SVG and the proper label. After the
            // student finishes an attempt and clicks Retake, the start section is shown again
            // but the same button DOM node is still labelled "Loading..." with no icon. Cache
            // the SVG string here so we can rebuild the button correctly in handleRetake and
            // in the .fail handlers below. Also covers the case where PHP rendered a Continue
            // button (in-progress attempt existed) and after that attempt is completed the
            // student wants to retake — we re-target the button to handleStartAttempt by
            // changing its id to 'va-start-quiz-btn' so a fresh attempt is created instead of
            // re-opening the now-closed in-progress attempt.
            var VA_PLAY_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>';

            function enableQuizButton() {
                var btn = document.getElementById('va-start-quiz-btn');
                if (btn) {
                    btn.disabled = false;
                    btn.classList.add('va-btn-enabled');
                }
                var continueBtn = document.getElementById('va-continue-attempt-btn');
                if (continueBtn) {
                    continueBtn.disabled = false;
                }
            }

            // FIX-VA-RETAKE-LOADING-BTN (v1.0.109): Restore the start-quiz-btn HTML to a clean
            // state (SVG icon + correct label). Used by handleRetake to clear the stale
            // "Loading..." text left by handleStartAttempt/handleContinueAttempt, and by the
            // .fail handlers when the AJAX round-trip errors out.
            function resetStartQuizButtonHtml(useRetakeLabel) {
                var btn = document.getElementById('va-start-quiz-btn');
                if (!btn) return;
                var label = useRetakeLabel
                    ? (config.retakeQuizLabel || 'Retake Quiz')
                    : (config.startQuizLabel || 'Start Quiz');
                btn.innerHTML = VA_PLAY_SVG + ' ' + escapeHtml(label);
            }

            // ==========================================
            // TEACHER: CREDIT LOADING
            // ==========================================

            function fetchCredits() {
                ajaxCall('getcredits').done(function(response) {
                    if (response.ok) {
                        var creditText = response.credits;
                        var el = document.getElementById('va-credit-balance');
                        if (el) {
                            el.textContent = creditText + ' credits';
                        }
                        var balanceEl = document.getElementById('va-balance-amount');
                        if (balanceEl) {
                            balanceEl.textContent = creditText;
                        }
                        var progressBalanceEl = document.getElementById('va-progress-balance');
                        if (progressBalanceEl) {
                            progressBalanceEl.textContent = creditText;
                        }
                        console.log('[VA] Credits loaded:', creditText);
                    }
                }).fail(function() {
                    console.error('[VA] Failed to load credits');
                });
            }

            // ==========================================
            // TEACHER: YOUTUBE URL VALIDATION & PREVIEW
            // ==========================================

            function handleYouTubeUrlInput() {
                var url = $('#va-youtube-url').val();
                var videoId = extractVideoId(url);
                var previewContainer = document.getElementById('va-youtube-preview');

                if (videoId) {
                    previewContainer.innerHTML = '<iframe width="100%" height="315" src="https://www.youtube.com/embed/' + videoId + '" frameborder="0" allowfullscreen></iframe>';
                    previewContainer.style.display = 'block';
                    $('#va-youtube-url').removeClass('va-input-error').addClass('va-input-valid');
                } else if (url.trim().length > 0) {
                    previewContainer.innerHTML = '<p style="color:#ef4444;">Invalid YouTube URL. Please use a valid youtube.com or youtu.be link.</p>';
                    previewContainer.style.display = 'block';
                    $('#va-youtube-url').addClass('va-input-error').removeClass('va-input-valid');
                } else {
                    previewContainer.style.display = 'none';
                    previewContainer.innerHTML = '';
                    $('#va-youtube-url').removeClass('va-input-error va-input-valid');
                }
                updateGenerateButtonState();
            }

            // ==========================================
            // TEACHER: WATCH MODE SELECTOR
            // ==========================================

            function handleWatchModeChange() {
                var mode = $('#va-watch-mode').val();
                var secondsField = document.getElementById('va-watch-seconds-field');
                if (secondsField) {
                    secondsField.style.display = (mode === 'seconds') ? 'block' : 'none';
                }
            }

            // ==========================================
            // TEACHER: QUESTION TYPE & SCENARIO CONTEXT
            // ==========================================

            function handleQuestionTypeChange() {
                var type = $('#va-question-type').val();
                var contextEl = document.getElementById('va-scenario-context');
                if (contextEl) {
                    contextEl.style.display = (type === 'scenario' || type === 'mixed') ? 'block' : 'none';
                }
            }

            // -- Industry & Sector Data  -  kept in sync with Content Creator --------------
            var INDUSTRIES = [
                'Aged Care', 'Agriculture', 'Automotive', 'Aviation', 'Building & Construction',
                'Business Services', 'Childcare', 'Community Services', 'Education', 'Electrical',
                'Engineering', 'Finance', 'Food Processing', 'Government', 'Healthcare',
                'Hospitality', 'Information Technology', 'Logistics', 'Manufacturing', 'Mining',
                'Plumbing', 'Retail', 'Security', 'Sport & Recreation', 'Tourism', 'Transport',
                'Utilities', 'Warehousing', 'Other'
            ];
            var INDUSTRY_SUBCATEGORIES = {
                'Aged Care': ['Residential Aged Care','Home Care Services','Dementia Care','Palliative Care','Community Aged Care','Retirement Living','Respite Care','Allied Health in Aged Care'],
                'Agriculture': ['Cropping & Grain','Livestock & Cattle','Dairy Farming','Horticulture','Viticulture & Wine','Aquaculture','Poultry','Shearing & Wool','Agricultural Contracting','Irrigation & Water Management'],
                'Automotive': ['Light Vehicle Mechanical','Heavy Vehicle Mechanical','Auto Electrical','Panel Beating & Spray Painting','Motorcycle Technician','Marine Mechanical','Automotive Parts & Accessories','Vehicle Sales','Tyre Fitting'],
                'Aviation': ['Commercial Aviation','General Aviation','Aircraft Maintenance','Ground Operations','Air Traffic Control','Cabin Crew','Aviation Security','Helicopter Operations'],
                'Building & Construction': ['Residential Construction','Commercial Construction','Civil Construction','Mining Construction','Industrial Construction','High-Rise Construction','Renovation & Refurbishment','Demolition','Scaffolding','Formwork','Concreting','Steel Fixing','Carpentry','Bricklaying','Tiling','Painting & Decorating','Plastering','Roofing','Glazing','Waterproofing'],
                'Business Services': ['Accounting & Bookkeeping','Human Resources','Marketing & Advertising','Legal Services','Consulting','Recruitment','Training & Development','Property Management','Cleaning Services','Security Services'],
                'Childcare': ['Long Day Care','Family Day Care','Outside School Hours Care','Kindergarten/Preschool','Occasional Care','In-Home Care','Special Needs Support','Early Intervention'],
                'Community Services': ['Disability Support','Mental Health Support','Youth Work','Family Services','Homelessness Services','Drug & Alcohol Services','Aboriginal & Torres Strait Islander Services','Refugee & Migrant Services','Domestic Violence Support','Case Management'],
                'Education': ['Primary Education','Secondary Education','Vocational Education (VET)','Higher Education/University','TAFE','Adult Education','Special Education','Early Childhood Education','Online/Distance Education','Education Support','Training Administration','School Administration','Private Training Provider (RTO)'],
                'Electrical': ['Domestic Electrical','Commercial Electrical','Industrial Electrical','Instrumentation','Refrigeration & Air Conditioning','Solar Installation','Data & Communications','Fire Protection Systems','Lift Installation'],
                'Engineering': ['Mechanical Engineering','Civil Engineering','Structural Engineering','Electrical Engineering','Chemical Engineering','Mining Engineering','Environmental Engineering','Project Engineering','Maintenance Engineering'],
                'Finance': ['Banking','Insurance','Financial Planning','Mortgage Broking','Credit & Lending','Superannuation','Investment Management','Payroll','Accounts Payable/Receivable','Auditing'],
                'Food Processing': ['Meat Processing','Seafood Processing','Dairy Processing','Bakery','Beverage Manufacturing','Confectionery','Fruit & Vegetable Processing','Ready Meals & Convenience Foods','Quality Assurance','Food Safety'],
                'Government': ['Local Government','State Government','Federal Government','Emergency Services','Regulatory & Compliance','Policy & Planning','Customer Service','Parks & Recreation','Infrastructure','Community Engagement'],
                'Healthcare': ['Acute Care/Hospital','Primary Care/GP','Allied Health','Mental Health','Community Health','Dental','Pharmacy','Pathology','Radiology','Emergency Services','Surgical','Rehabilitation','Infection Control','Aged Care Nursing','Midwifery','Disability Health','Aboriginal Health'],
                'Hospitality': ['Hotels & Accommodation','Restaurants & Cafes','Bars & Pubs','Catering','Events & Functions','Fast Food & Quick Service','Clubs & Gaming','Commercial Cookery','Patisserie','Front Office','Housekeeping'],
                'Information Technology': ['Software Development','Network Administration','Cybersecurity','Cloud Computing','Database Administration','IT Support/Help Desk','Web Development','Data Analytics','Systems Administration','IT Project Management'],
                'Logistics': ['Supply Chain Management','Freight Forwarding','Customs & Border','Inventory Management','Distribution','Third-Party Logistics (3PL)','Last Mile Delivery','Cold Chain Logistics','Dangerous Goods'],
                'Manufacturing': ['Food & Beverage Manufacturing','Pharmaceutical Manufacturing','Chemical Manufacturing','Metal Fabrication','Plastics & Rubber','Textiles','Furniture Manufacturing','Electronics Manufacturing','Printing','Packaging','Process Manufacturing'],
                'Mining': ['Open Cut Mining','Underground Mining','Coal Mining','Iron Ore','Gold Mining','Mineral Processing','Exploration','Drilling','Mine Site Services','Tailings Management','Mine Rehabilitation'],
                'Plumbing': ['Domestic Plumbing','Commercial Plumbing','Industrial Plumbing','Gas Fitting','Roofing & Drainage','Fire Protection Plumbing','Irrigation','Water Treatment','Mechanical Services'],
                'Retail': ['Supermarkets & Grocery','Fashion & Apparel','Electronics & Technology','Hardware & Building','Pharmacy Retail','Furniture & Homewares','Automotive Retail','Sporting Goods','Online/E-commerce','Luxury Retail'],
                'Security': ['Static Security','Mobile Patrol','Event Security','Close Protection','Loss Prevention','Corporate Security','Cash in Transit','CCTV & Monitoring','Access Control','Cybersecurity Operations'],
                'Sport & Recreation': ['Fitness & Personal Training','Aquatics','Outdoor Recreation','Sports Coaching','Sports Administration','Community Recreation','Event Management','Golf & Turf Management','Sports Medicine Support'],
                'Tourism': ['Travel Agencies','Tour Operations','Attractions & Theme Parks','Eco-Tourism','Adventure Tourism','Cultural Tourism','Cruise Operations','Tourism Marketing','Visitor Information Services','Indigenous Tourism'],
                'Transport': ['Road Transport','Rail Transport','Maritime Transport','Air Transport','Public Transport','Taxi & Rideshare','Courier Services','Bus Operations','Heavy Vehicle Operations','Transport Administration'],
                'Utilities': ['Electricity Generation','Electricity Distribution','Gas Distribution','Water Supply','Wastewater Treatment','Renewable Energy','Smart Grid','Meter Reading','Network Maintenance'],
                'Warehousing': ['General Warehousing','Cold Storage','Distribution Centres','Cross-Docking','Hazardous Goods Storage','Automated Warehousing','Order Fulfilment','Returns Processing','Inventory Control'],
                'Other': ['General Industry','Cross-Industry','Emerging Industry']
            };
            function getIndustrySectors(industry) { return INDUSTRY_SUBCATEGORIES[industry] || []; }
            // ----------------------------------------------------------------------------

            function fetchIndustries() {
                var $select = $('#va-scenario-industry');
                INDUSTRIES.forEach(function(ind) {
                    $select.append($('<option>').val(ind).text(ind));
                });
                // Wire up sector dropdown when industry changes
                $select.on('change', function() {
                    var industry = $(this).val();
                    var $sectorSelect = $('#va-scenario-sector');
                    $sectorSelect.empty().append($('<option>').val('').text('Select sector (optional)...'));
                    getIndustrySectors(industry).forEach(function(s) {
                        $sectorSelect.append($('<option>').val(s).text(s));
                    });
                    $sectorSelect.prop('disabled', !industry);
                });
            }

            // ==========================================
            // TEACHER: GENERATE BUTTON STATE
            // ==========================================

            function updateGenerateButtonState() {
                var btn = document.getElementById('va-generate-btn');
                if (!btn) { return; }
                var mediaType = $('#va-media-type').val() || 'video';
                var transcript = ($('#va-transcript').val() || '').trim();

                if (mediaType === 'audio') {
                    var hasAudio = !!(config.audioUrl);
                    btn.disabled = !(hasAudio && transcript.length > 0);
                } else {
                    var url = $('#va-youtube-url').val() || '';
                    var videoId = extractVideoId(url);
                    btn.disabled = !(videoId && transcript.length > 0);
                }
            }

            // ==========================================
            // TEACHER: COST CALCULATION
            // ==========================================

            function stripTimestamps(text) {
                return text.replace(/^\s*\d{1,2}:\d{2}(?::\d{2})?\s*$/gm, '').replace(/\n{2,}/g, '\n');
            }

            function updateCostDisplay() {
                var transcript = ($('#va-transcript').val() || '').trim();
                var cleanTranscript = stripTimestamps(transcript);
                var wordCount = cleanTranscript ? cleanTranscript.split(/\s+/).filter(function(w) { return w.length > 0; }).length : 0;
                var baseCost = Math.max(5, Math.ceil(wordCount / 150) * 5);

                var voiceoverOn = $('#va-voiceover-toggle').is(':checked');
                var numQuestions = parseInt($('#va-num-questions').val(), 10) || 10;
                var voiceoverCost = voiceoverOn ? numQuestions : 0;
                var cost = baseCost + voiceoverCost;

                var formulaEl = document.getElementById('va-credit-formula');
                var progressFormulaEl = document.getElementById('va-progress-credit-formula');
                var costEl = document.getElementById('va-credit-cost');
                var previewStats = document.getElementById('preview-stats');
                var transcriptCostInfo = document.getElementById('va-transcript-cost-info');
                var transcriptWordCount = document.getElementById('va-transcript-word-count');
                var transcriptCreditCost = document.getElementById('va-transcript-credit-cost');

                var formulaText;
                if (wordCount > 0) {
                    if (voiceoverOn) {
                        formulaText = cost + ' credits (' + wordCount + ' words = ' + baseCost + ' credits + ' + voiceoverCost + ' voiceover credits for ' + numQuestions + ' questions)';
                    } else {
                        formulaText = cost + ' credits (' + wordCount + ' words = ' + cost + ' credits)';
                    }
                } else {
                    formulaText = 'Paste transcript to see cost';
                }

                if (formulaEl) { formulaEl.textContent = formulaText; }
                if (progressFormulaEl) { progressFormulaEl.textContent = formulaText; }
                if (costEl) { costEl.textContent = cost + ' credits'; }

                if (transcriptWordCount) { transcriptWordCount.textContent = wordCount; }
                if (transcriptCreditCost) { transcriptCreditCost.textContent = cost; }

                var transcriptCostSpan = transcriptCostInfo ? transcriptCostInfo.querySelector('span') : null;
                if (transcriptCostSpan && wordCount > 0) {
                    var costDesc = '<strong>' + wordCount + '</strong> words &mdash; <strong>' + cost + '</strong> credits (5 per 150 words';
                    if (voiceoverOn) {
                        costDesc += ' + 1 per question voiceover';
                    }
                    costDesc += ', minimum 5)';
                    transcriptCostSpan.innerHTML = costDesc;
                }

                if (transcriptCostInfo) {
                    transcriptCostInfo.style.display = wordCount > 0 ? 'flex' : 'none';
                }

                if (previewStats && wordCount > 0) {
                    previewStats.style.display = 'block';
                } else if (previewStats && wordCount === 0) {
                    previewStats.style.display = 'none';
                }

                updateGenerateButtonState();
            }

            // ==========================================
            // TEACHER: VOICE SETTINGS
            // ==========================================

            function handleGenderChange(targetPrefix) {
                var prefix = targetPrefix || 'va';
                var gender = $('#' + prefix + '-voice-gender').val();
                var $style = $('#' + prefix + '-voice-style');
                $style.empty();

                if (gender === 'female') {
                    $style.append($('<option>').val('Aoede').text('Aoede (warm, friendly)'));
                    $style.append($('<option>').val('Kore').text('Kore (clear, professional)'));
                    $style.append($('<option>').val('Leda').text('Leda (soft, nurturing)'));
                    $style.append($('<option>').val('Zephyr').text('Zephyr (energetic, youthful)'));
                } else {
                    $style.append($('<option>').val('Puck').text('Puck (friendly, casual)'));
                    $style.append($('<option>').val('Charon').text('Charon (deep, authoritative)'));
                    $style.append($('<option>').val('Fenrir').text('Fenrir (warm, mature)'));
                    $style.append($('<option>').val('Orus').text('Orus (clear, professional)'));
                }
            }

            // ==========================================
            // TEACHER: GENERATE QUESTIONS
            // ==========================================

            function handleGenerate() {
                var mediaType = $('#va-media-type').val() || 'video';
                var youtubeUrl = $('#va-youtube-url').val() || '';
                var audioUrl = config.audioUrl || '';
                var transcript = $('#va-transcript').val() || '';
                var numQuestions = parseInt($('#va-num-questions').val(), 10) || 5;
                var watchMode = $('#va-watch-mode').val() || 'none';
                var watchSeconds = parseInt($('#va-watch-seconds').val(), 10) || 60;

                if (mediaType === 'audio') {
                    if (!audioUrl) {
                        alert('No audio file uploaded. Please go to the activity settings to upload an audio file first.');
                        return;
                    }
                } else {
                    var videoId = extractVideoId(youtubeUrl);
                    if (!videoId) {
                        alert('Please enter a valid YouTube URL.');
                        return;
                    }
                }

                if (!transcript.trim()) {
                    alert('Please enter a transcript or content for question generation.');
                    return;
                }

                var voiceoverEnabled = $('#va-voiceover-toggle').is(':checked') ? 1 : 0;
                var voiceLanguage = $('#va-voice-language').val() || 'en-AU';
                var voiceGender = $('#va-voice-gender').val() || 'female';
                var voiceStyle = $('#va-voice-style').val() || 'Aoede';

                var questionType = $('#va-question-type').val() || 'application';
                var bloomLevel = parseInt($('#va-bloom-level').val(), 10) || 3;
                var scenarioCountry = '';
                var scenarioIndustry = '';
                var scenarioSubindustry = '';
                var scenarioJobLevel = '';
                var scenarioJobRoles = '';
                if (questionType === 'scenario' || questionType === 'mixed') {
                    scenarioCountry = $('#va-scenario-country').val() || '';
                    scenarioIndustry = $('#va-scenario-industry').val() || '';
                    scenarioSubindustry = $('#va-scenario-sector').val() || '';
                    scenarioJobLevel = vaSelectedJobLevels.join(', ');
                    scenarioJobRoles = vaSelectedJobRoles.join(', ');
                }

                var extraInstructions = ($('#va-extra-instructions').val() || '').trim();

                var selectedFormats = [];
                $('.va-format-grid input[type="checkbox"]:checked').each(function() {
                    selectedFormats.push($(this).val());
                });
                if (selectedFormats.length === 0) {
                    selectedFormats = ['mcq'];
                }

                console.log('[VA] Generating questions:', {
                    youtubeUrl: youtubeUrl,
                    numQuestions: numQuestions,
                    watchMode: watchMode,
                    voiceoverEnabled: voiceoverEnabled,
                    questionType: questionType,
                    selectedFormats: selectedFormats
                });

                document.getElementById('va-form-section').style.display = 'none';
                document.getElementById('va-progress-section').style.display = 'block';
                document.getElementById('va-progress-fill').style.width = '0%';
                document.getElementById('va-progress-message').textContent = 'Starting generation...';

                ajaxCall('generate', {
                    mediaType: mediaType,
                    youtubeUrl: youtubeUrl,
                    audioUrl: audioUrl,
                    transcript: transcript,
                    numQuestions: numQuestions,
                    watchMode: watchMode,
                    watchSeconds: watchSeconds,
                    voiceoverEnabled: voiceoverEnabled,
                    voiceLanguage: voiceLanguage,
                    voiceGender: voiceGender,
                    voiceId: voiceStyle,
                    questionType: questionType,
                    bloomLevel: bloomLevel,
                    extraInstructions: extraInstructions,
                    scenarioCountry: scenarioCountry,
                    scenarioIndustry: scenarioIndustry,
                    scenarioSubindustry: scenarioSubindustry,
                    scenarioJobLevel: scenarioJobLevel,
                    scenarioJobRoles: scenarioJobRoles,
                    selectedFormats: JSON.stringify(selectedFormats)
                }).done(function(response) {
                    console.log('[VA] Generate response:', JSON.stringify(response));
                    if (response.ok && response.jobId) {
                        console.log('[VA] Job started:', response.jobId);
                        currentJobId = response.jobId;
                        startStatusPolling();
                    } else if (response.error === 'INSUFFICIENT_CREDITS') {
                        alert('Insufficient credits. Please purchase more at lms-labs.com');
                        document.getElementById('va-progress-section').style.display = 'none';
                        document.getElementById('va-form-section').style.display = 'block';
                    } else {
                        alert(response.error || 'Failed to start generation');
                        document.getElementById('va-progress-section').style.display = 'none';
                        document.getElementById('va-form-section').style.display = 'block';
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Generate AJAX error:', status, error);
                    var msg = 'Request failed. Please try again.';
                    if (status === 'timeout') {
                        msg = 'Request timed out. Please try again.';
                    } else if (xhr.status === 0) {
                        msg = 'Could not connect to the server. Please check your internet connection.';
                    } else if (xhr.responseText) {
                        try {
                            var errResp = JSON.parse(xhr.responseText);
                            if (errResp.error) msg = errResp.error;
                        } catch (e) {
                            console.error('[VA] Could not parse error response');
                        }
                    }
                    alert(msg);
                    document.getElementById('va-progress-section').style.display = 'none';
                    document.getElementById('va-form-section').style.display = 'block';
                });
            }

            // ==========================================
            // TEACHER: STATUS POLLING
            // ==========================================

            function startStatusPolling() {
                statusPollFailures = 0;
                statusPollingInterval = setInterval(checkStatus, 2000);
            }

            function checkStatus() {
                ajaxCall('status', {
                    jobId: currentJobId
                }).done(function(response) {
                    statusPollFailures = 0;
                    if (response.ok) {
                        document.getElementById('va-progress-fill').style.width = response.progress + '%';
                        document.getElementById('va-progress-message').textContent = response.progressMessage || '';

                        if (response.status === 'completed') {
                            clearInterval(statusPollingInterval);
                            quizData = response.questions;
                            // FIX-VA-VOICEOVER-DIAG (v1.0.103): Log audioData status per
                            // question after polling completes so we can see whether the
                            // server actually returned voiceover bytes for an initial gen.
                            if (Array.isArray(quizData)) {
                                quizData.forEach(function(dq, di) {
                                    var ad = dq && dq.audioData;
                                    var adLen = Array.isArray(ad) ? ad.length : (ad ? 1 : 0);
                                    var firstLen = Array.isArray(ad) && ad[0] ? ad[0].length : 0;
                                    console.log('[VA-DIAG] gen Q' + (di + 1) + ' type=' + (dq && dq.type) +
                                        ' audioData=' + (ad === null ? 'null' : (ad === undefined ? 'undefined' : (Array.isArray(ad) ? 'array' : typeof ad))) +
                                        ' len=' + adLen + ' firstB64=' + firstLen);
                                });
                            }
                            showQuizReady();
                        } else if (response.status === 'failed') {
                            clearInterval(statusPollingInterval);
                            alert(response.error || 'Generation failed');
                            document.getElementById('va-progress-section').style.display = 'none';
                            document.getElementById('va-form-section').style.display = 'block';
                        }
                    }
                }).fail(function(xhr, status, error) {
                    statusPollFailures++;
                    console.error('[VA] Status check failed (attempt ' + statusPollFailures + '/' + MAX_POLL_FAILURES + '):', status, error);
                    if (statusPollFailures >= MAX_POLL_FAILURES) {
                        clearInterval(statusPollingInterval);
                        alert('Lost connection to the server. Please refresh the page to check.');
                        document.getElementById('va-progress-section').style.display = 'none';
                        document.getElementById('va-form-section').style.display = 'block';
                    }
                });
            }

            // ==========================================
            // TEACHER: REGEN JOB POLL
            // ==========================================

            // FIX-VA-REGEN-ASYNC (v1.0.90): The external API's regenerateinstructions endpoint
            // now starts an async background job (returning {ok:true, jobId:"..."} immediately)
            // instead of waiting synchronously. All three regeneration handlers (doReadyBatch,
            // doEditBatch, doSingleRequest) were only checking for response.questions and falling
            // into the retry branch every time because the jobId response has no .questions field.
            // Fix: add pollRegenJob() that polls the existing 'status' action until the job
            // completes or fails, then delivers questions via onSuccess / surfaces error via
            // onFailure. Retries (2s polling, max 90 polls = 3 minutes) happen transparently.
            function pollRegenJob(jobId, onProgress, onSuccess, onFailure) {
                var pollCount = 0;
                var maxPolls = 90; // 90 × 2 000 ms = 3 min max
                var failStreak = 0;
                var maxFailStreak = 5;
                var pollTimer = setInterval(function() {
                    ajaxCall('status', { jobId: jobId }).done(function(sr) {
                        failStreak = 0;
                        pollCount++;
                        if (sr.ok) {
                            if (typeof onProgress === 'function') {
                                onProgress(sr.progress || 0, sr.progressMessage || '');
                            }
                            if (sr.status === 'completed') {
                                clearInterval(pollTimer);
                                if (sr.questions && sr.questions.length > 0) {
                                    onSuccess(sr.questions);
                                } else {
                                    onFailure('Regeneration completed but no questions were returned.');
                                }
                                return;
                            }
                            if (sr.status === 'failed') {
                                clearInterval(pollTimer);
                                onFailure(sr.error || 'Regeneration failed on the server.');
                                return;
                            }
                        }
                        if (pollCount >= maxPolls) {
                            clearInterval(pollTimer);
                            onFailure('Regeneration timed out after 3 minutes. Please try again.');
                        }
                    }).fail(function() {
                        failStreak++;
                        if (failStreak >= maxFailStreak) {
                            clearInterval(pollTimer);
                            onFailure('Connection lost during regeneration. Please try again.');
                        }
                    });
                }, 2000);
            }

            // ==========================================
            // TEACHER: QUIZ READY
            // ==========================================

            function showQuizReady() {
                saveQuestionsToDatabase();

                document.getElementById('va-progress-section').style.display = 'none';
                document.getElementById('va-ready-section').style.display = 'block';

                var voiceoverOn = $('#va-voiceover-toggle').is(':checked');
                var summaryEl = document.getElementById('va-ready-summary');
                if (summaryEl) {
                    summaryEl.textContent = quizData.length + ' questions generated' + (voiceoverOn ? ' with voiceover!' : ' successfully!');
                }

                updateRegenCountDisplay();
                fetchCredits();
            }

            function saveQuestionsToDatabase() {
                var questionsForDb = quizData.map(function(q) {
                    // FIX-VA-NULL-CORRECTANSWER (v1.0.130): normalise correctAnswer to
                    // an integer before persisting so null/undefined never reaches the DB
                    // and causes the shuffle to silently default to option 0.
                    var caRaw = q.correctAnswer != null ? q.correctAnswer : (q.correctIndex != null ? q.correctIndex : 0);
                    var caNorm = parseInt(caRaw, 10);
                    if (isNaN(caNorm) || caNorm < 0) { caNorm = 0; }
                    var qObj = {
                        type: q.type || 'mcq',
                        question: q.question,
                        options: q.options || [],
                        explanations: q.explanations || [],
                        correctAnswer: caNorm,
                        audioData: q.audioData || null
                    };
                    if (q.pairs) qObj.pairs = q.pairs;
                    if (q.correctOrder) qObj.correctOrder = q.correctOrder;
                    if (q.columns) qObj.columns = q.columns;
                    if (q.columnA) qObj.columnA = q.columnA;
                    if (q.columnB) qObj.columnB = q.columnB;
                    if (q.items) qObj.items = q.items;
                    if (q.categories) qObj.categories = q.categories;
                    if (q.explanation) qObj.explanation = q.explanation;
                    if (q.cards) qObj.cards = q.cards;
                    if (q.correctIndex !== undefined) qObj.correctIndex = q.correctIndex;
                    if (q.statements) qObj.statements = q.statements;
                    if (q.blanks) qObj.blanks = q.blanks;
                    if (q.text) qObj.text = q.text;
                    if (q.distractors) qObj.distractors = q.distractors;
                    // FIX-VA-TIMESTAMP-SAVE: preserve timestamp_seconds so "Show chapter
                    // timestamp links" buttons survive the DB round-trip.
                    if (q.timestamp_seconds != null) qObj.timestamp_seconds = q.timestamp_seconds;
                    return qObj;
                });

                // FIX-VA-VOICEOVER-DIAG (v1.0.103): log audioData status of each question
                // immediately before posting to savequestions so we can confirm what the
                // teacher's browser actually sends to the plugin.
                questionsForDb.forEach(function(dq, di) {
                    var ad = dq && dq.audioData;
                    var adLen = Array.isArray(ad) ? ad.length : (ad ? 1 : 0);
                    var firstLen = Array.isArray(ad) && ad[0] ? ad[0].length : 0;
                    console.log('[VA-DIAG] save Q' + (di + 1) + ' type=' + (dq && dq.type) +
                        ' audioData=' + (ad === null ? 'null' : (ad === undefined ? 'undefined' : (Array.isArray(ad) ? 'array' : typeof ad))) +
                        ' len=' + adLen + ' firstB64=' + firstLen);
                });

                ajaxCall('savequestions', {
                    questions: JSON.stringify(questionsForDb)
                }).done(function(response) {
                    if (response.ok) {
                        console.log('[VA] Questions saved to database:', response.saved);
                    } else {
                        console.error('[VA] Failed to save questions:', response.error);
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Save questions request failed:', status, error);
                });
            }

            // ==========================================
            // TEACHER: EDIT QUESTIONS
            // ==========================================

            function showEditSection() {
                originalQuizData = JSON.parse(JSON.stringify(quizData));
                document.getElementById('va-ready-section').style.display = 'none';
                document.getElementById('va-edit-section').style.display = 'block';
                buildEditForms();
            }

            function buildEditForms() {
                var container = document.getElementById('va-edit-questions-container');
                if (!container) return;
                container.innerHTML = '';

                var letters = ['A', 'B', 'C', 'D'];
                var typeLabels = {
                    'mcq': 'Multiple Choice',
                    'cardselect': 'Card Select',
                    'matching': 'Matching',
                    'ordering': 'Ordering',
                    'columnsort': 'Column Sort',
                    'categorysort': 'Category Sort',
                    'fillinblank': 'Fill in the Blank'
                };

                quizData.forEach(function(q, qIdx) {
                    var qDiv = document.createElement('div');
                    qDiv.className = 'va-edit-question';
                    qDiv.setAttribute('data-index', qIdx);
                    qDiv.setAttribute('data-type', q.type || 'mcq');

                    var qtype = q.type || 'mcq';
                    var typeBadge = typeLabels[qtype] || qtype;
                    var html = '<div class="va-edit-question-header">';
                    html += '<span>Question ' + (qIdx + 1) + '</span>';
                    html += '<div class="va-edit-question-header-right">';
                    html += '<span class="va-edit-type-badge va-edit-type-' + qtype + '">' + escapeHtml(typeBadge) + '</span>';
                    html += '<button class="va-delete-question-btn" data-index="' + qIdx + '" title="Remove this question" type="button">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
                    html += ' Remove</button>';
                    html += '</div>';
                    html += '</div>';
                    html += '<textarea class="va-edit-question-text" rows="3">' + escapeHtml(q.question) + '</textarea>';

                    if (qtype === 'mcq') {
                        // v1.0.43 FIX-VA-SHUFFLE-EDIT: When shuffle is enabled,
                        // q.options/explanations are in shuffled order and
                        // q.correctAnswer is the shuffled index. Editing in shuffled
                        // order means saves go to the server in shuffled order  - 
                        // the next student sees double-shuffled options with the wrong
                        // correct answer. De-shuffle to original order using
                        // shuffledToOriginal so edits always save in canonical order.
                        var editOptions = q.options ? q.options.slice() : [];
                        var editExplanations = q.explanations ? q.explanations.slice() : [];
                        var editCorrect = q.originalCorrectIndex !== undefined
                            ? q.originalCorrectIndex
                            : (q.correctAnswer || 0);
                        if (q.shuffledToOriginal && q.shuffledToOriginal.length === editOptions.length) {
                            var _dsOpts = new Array(editOptions.length);
                            var _dsExpls = new Array(editOptions.length);
                            q.shuffledToOriginal.forEach(function(origIdx, shuffIdx) {
                                _dsOpts[origIdx]  = editOptions[shuffIdx] || '';
                                _dsExpls[origIdx] = editExplanations[shuffIdx] || '';
                            });
                            editOptions = _dsOpts;
                            editExplanations = _dsExpls;
                        }

                        for (var i = 0; i < 4; i++) {
                            var optionText = editOptions[i] || '';
                            var explanationText = editExplanations[i] || '';
                            var isCorrect = (editCorrect === i);

                            html += '<div class="va-edit-option" data-optindex="' + i + '">';
                            html += '<label class="va-edit-option-label">';
                            html += '<input type="radio" name="va-correct-' + qIdx + '" value="' + i + '"' + (isCorrect ? ' checked' : '') + '>';
                            html += ' <span class="va-option-letter">' + letters[i] + '</span>';
                            html += '</label>';
                            html += '<input type="text" class="va-edit-option-text" value="' + escapeHtml(optionText) + '" placeholder="Option ' + letters[i] + '">';
                            html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation for ' + letters[i] + '">' + escapeHtml(explanationText) + '</textarea>';
                            html += '</div>';
                        }
                    } else if (qtype === 'cardselect') {
                        var cards = q.cards || [];
                        var correctIdx = q.correctIndex !== undefined ? q.correctIndex : 0;
                        // FIX-VA-EDIT-ICON-PICKER: Add icon selector per card so teachers can change icons.
                        // Previously icons were shown as read-only SVG with no way to edit them.
                        var vaIconNames = ['shield','book','lightbulb','gear','heart','star','check','warning','clock','target','users','tool','flag','eye','lock'];
                        var vaIconLabels = {shield:'Shield',book:'Book',lightbulb:'Light Bulb',gear:'Gear',heart:'Heart',star:'Star',check:'Check',warning:'Warning',clock:'Clock',target:'Target',users:'People',tool:'Tool',flag:'Flag',eye:'Eye',lock:'Lock'};
                        // FIX-VA-CARDSELECT-PERCARD-EDITOR (v1.0.116): per-card explanations
                        // were generated by the AI under v1.0.110+ but were invisible in the
                        // editor and silently dropped on Save (saveEdits never collected them).
                        // Now: render one textarea per card bound to q.explanations[ci] so the
                        // teacher can see and edit each per-card feedback message; saveEdits
                        // persists the array. Legacy questions with no q.explanations[] render
                        // empty per-card boxes the teacher can fill in.
                        var perCardExpls = Array.isArray(q.explanations) ? q.explanations : [];
                        for (var ci = 0; ci < cards.length; ci++) {
                            var card = cards[ci];
                            var isCardCorrect = (ci === correctIdx);
                            var currentIcon = card.icon || 'star';
                            var perCardExpl = perCardExpls[ci] || '';
                            var perCardPh = isCardCorrect
                                ? 'Feedback when this (correct) card is picked. Start with "Correct."'
                                : 'Feedback when this card is picked. Start with "Incorrect." and explain why this card is wrong without naming the correct one.';

                            // FIX-VA-CARDSELECT-FIELD-LAYOUT (v1.0.122): Previously the label and
                            // description inputs were bare <input type="text"> elements placed directly
                            // inside .va-edit-option with no enclosing structure or CSS rules. Because
                            // <input> is inline-block by default they sat side-by-side in a cramped row,
                            // both truncated, with no field labels. Teachers couldn't tell which was which
                            // and couldn't see full text in either box. Fix: wrap each field in its own
                            // .va-edit-card-field-row div with a .va-edit-card-field-label so every field
                            // is clearly labelled and takes up the full available width.
                            html += '<div class="va-edit-option va-edit-card-option" data-optindex="' + ci + '">';
                            html += '<div class="va-edit-card-header-row">';
                            html += '<label class="va-edit-option-label">';
                            html += '<input type="radio" name="va-correct-' + qIdx + '" value="' + ci + '"' + (isCardCorrect ? ' checked' : '') + '>';
                            html += ' <span class="va-option-letter">' + getVAIcon(currentIcon) + '</span>';
                            html += '</label>';
                            html += '<span class="va-edit-card-correct-badge' + (isCardCorrect ? ' va-edit-card-correct-badge--active' : '') + '">' + (isCardCorrect ? 'Correct answer' : 'Incorrect option') + '</span>';
                            html += '</div>';
                            html += '<div class="va-edit-card-field-row">';
                            html += '<label class="va-edit-card-field-label">Label</label>';
                            html += '<input type="text" class="va-edit-card-label" value="' + escapeHtml(card.label || '') + '" placeholder="Card label">';
                            html += '</div>';
                            html += '<div class="va-edit-card-field-row">';
                            html += '<label class="va-edit-card-field-label">Description</label>';
                            html += '<input type="text" class="va-edit-card-desc" value="' + escapeHtml(card.description || '') + '" placeholder="Card description (shown on card back)">';
                            html += '</div>';
                            html += '<div class="va-edit-card-icon-row">';
                            html += '<label class="va-edit-icon-label">Icon:</label>';
                            html += '<select class="va-edit-card-icon">';
                            vaIconNames.forEach(function(iname) {
                                html += '<option value="' + iname + '"' + (iname === currentIcon ? ' selected' : '') + '>' + (vaIconLabels[iname] || iname) + '</option>';
                            });
                            html += '</select>';
                            html += '<span class="va-edit-icon-preview">' + getVAIcon(currentIcon) + '</span>';
                            html += '</div>';
                            html += '<div class="va-edit-card-expl-row">';
                            html += '<label class="va-edit-icon-label">Per-card feedback:</label>';
                            html += '<textarea class="va-edit-card-expl" rows="2" placeholder="' + escapeHtml(perCardPh) + '">' + escapeHtml(perCardExpl) + '</textarea>';
                            html += '</div>';
                            html += '</div>';
                        }
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Overall question feedback (shown beneath the per-card feedback)">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'matching') {
                        var pairs = q.pairs || [];
                        html += '<div class="va-edit-matching-section">';
                        html += '<div class="va-edit-section-label">Pairs (left &rarr; right):</div>';
                        for (var mi = 0; mi < pairs.length; mi++) {
                            html += '<div class="va-edit-pair" data-pairindex="' + mi + '">';
                            html += '<input type="text" class="va-edit-pair-left" value="' + escapeHtml(pairs[mi].left || pairs[mi].term || '') + '" placeholder="Term ' + (mi + 1) + '">';
                            html += '<span class="va-edit-pair-arrow">&rarr;</span>';
                            html += '<input type="text" class="va-edit-pair-right" value="' + escapeHtml(pairs[mi].right || pairs[mi].definition || '') + '" placeholder="Match ' + (mi + 1) + '">';
                            html += '</div>';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'ordering') {
                        var items = q.items || [];
                        html += '<div class="va-edit-ordering-section">';
                        html += '<div class="va-edit-section-label">Items (in correct order, top to bottom):</div>';
                        for (var oi = 0; oi < items.length; oi++) {
                            html += '<div class="va-edit-order-item" data-orderindex="' + oi + '">';
                            html += '<span class="va-edit-order-num">' + (oi + 1) + '.</span>';
                            html += '<input type="text" class="va-edit-order-text" value="' + escapeHtml(items[oi]) + '" placeholder="Step ' + (oi + 1) + '">';
                            html += '</div>';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'columnsort') {
                        var colA = q.columnA || 'Column A';
                        var colB = q.columnB || 'Column B';
                        var csItems = q.items || [];
                        html += '<div class="va-edit-columnsort-section">';
                        html += '<div class="va-edit-section-label">Column names:</div>';
                        html += '<div class="va-edit-col-headers">';
                        html += '<input type="text" class="va-edit-col-a-name" value="' + escapeHtml(colA) + '" placeholder="Column A name">';
                        html += '<input type="text" class="va-edit-col-b-name" value="' + escapeHtml(colB) + '" placeholder="Column B name">';
                        html += '</div>';
                        html += '<div class="va-edit-section-label">Items (select which column each belongs to):</div>';
                        for (var ci2 = 0; ci2 < csItems.length; ci2++) {
                            html += '<div class="va-edit-colsort-item" data-csindex="' + ci2 + '">';
                            html += '<input type="text" class="va-edit-colsort-text" value="' + escapeHtml(csItems[ci2].text || '') + '" placeholder="Item ' + (ci2 + 1) + '">';
                            html += '<label class="va-edit-col-radio"><input type="radio" name="va-col-' + qIdx + '-' + ci2 + '" value="A"' + (csItems[ci2].column === 'A' ? ' checked' : '') + '> A</label>';
                            html += '<label class="va-edit-col-radio"><input type="radio" name="va-col-' + qIdx + '-' + ci2 + '" value="B"' + (csItems[ci2].column === 'B' ? ' checked' : '') + '> B</label>';
                            html += '</div>';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'categorysort') {
                        var cats = q.categories || [];
                        var catItems = q.items || [];
                        html += '<div class="va-edit-categorysort-section">';
                        html += '<div class="va-edit-section-label">Category names:</div>';
                        html += '<div class="va-edit-cat-names">';
                        for (var cn = 0; cn < cats.length; cn++) {
                            html += '<input type="text" class="va-edit-cat-name" data-catindex="' + cn + '" value="' + escapeHtml(cats[cn]) + '" placeholder="Category ' + (cn + 1) + '">';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-section-label">Items (select category for each):</div>';
                        for (var csi = 0; csi < catItems.length; csi++) {
                            html += '<div class="va-edit-catsort-item" data-catsortindex="' + csi + '">';
                            html += '<input type="text" class="va-edit-catsort-text" value="' + escapeHtml(catItems[csi].text || '') + '" placeholder="Item ' + (csi + 1) + '">';
                            html += '<select class="va-edit-catsort-select" data-itemindex="' + csi + '">';
                            // FIX-VA-CATSORT-EDIT-NORMALIZE (v1.0.105): The AI generates `item.category` as the STRING
                            // CATEGORY NAME ("Mammals"), not as a numeric index. The previous strict equality
                            // `catItems[csi].category === catOpt` only matched number === number, so the dropdown
                            // never pre-selected the right option for ANY item — the browser silently fell back to
                            // the first <option> (index 0). When the teacher hit Save without touching the dropdown,
                            // every item was written back as category=0, dumping the entire question into the first
                            // bucket and corrupting the question. Now matches numeric index, the string form of the
                            // numeric index, OR the canonical string category name.
                            var savedCat = catItems[csi].category;
                            for (var catOpt = 0; catOpt < cats.length; catOpt++) {
                                var isSelected = savedCat === catOpt
                                    || savedCat === String(catOpt)
                                    || savedCat === cats[catOpt];
                                html += '<option value="' + catOpt + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(cats[catOpt]) + '</option>';
                            }
                            html += '</select>';
                            html += '</div>';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'flashcards') {
                        // BUG-VA-FLASHCARD-EDIT (v1.0.105): Flashcards previously had no edit form below the question
                        // textarea, so teachers could change the (often empty) stem but never the actual front/back text
                        // of the cards or the overall explanation. Now renders one row per card (front + back inputs)
                        // plus an overall explanation textarea. Per the AI schema flashcards have no top-level
                        // "question" field, so the stem is treated as optional in the validator below.
                        var fcCards = q.cards || [];
                        html += '<div class="va-edit-flashcards-section">';
                        html += '<div class="va-edit-section-label">Cards (front = prompt, back = answer):</div>';
                        for (var fci = 0; fci < fcCards.length; fci++) {
                            var fcCard = fcCards[fci] || {};
                            html += '<div class="va-edit-flashcard-row" data-fcindex="' + fci + '">';
                            html += '<span class="va-edit-order-num">' + (fci + 1) + '.</span>';
                            html += '<div class="va-edit-flashcard-fields">';
                            html += '<textarea class="va-edit-fc-front" rows="2" placeholder="Front (prompt) ' + (fci + 1) + '">' + escapeHtml(fcCard.front || '') + '</textarea>';
                            html += '<textarea class="va-edit-fc-back" rows="2" placeholder="Back (answer) ' + (fci + 1) + '">' + escapeHtml(fcCard.back || '') + '</textarea>';
                            html += '</div>';
                            html += '</div>';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation (shown after the set is complete)">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'truefalseswipe') {
                        // BUG-VA-TFS-EDIT (v1.0.105): True/False questions previously had no editor for the per-statement
                        // text, the True/False answer, or the per-statement explanation. The teacher could only retype
                        // the question stem; the actual statements stayed locked at whatever the AI produced. Now renders
                        // one row per statement (text input + True/False radio + per-statement explanation textarea)
                        // plus an overall explanation textarea.
                        var tfStatements = q.statements || [];
                        html += '<div class="va-edit-tfs-section">';
                        html += '<div class="va-edit-section-label">Statements (mark each True or False, with an explanation):</div>';
                        for (var tsi = 0; tsi < tfStatements.length; tsi++) {
                            var stmt = tfStatements[tsi] || {};
                            var stmtTrue = stmt.correct === true || stmt.correct === 'true' || stmt.correct === 1;
                            html += '<div class="va-edit-tfs-row" data-tfsindex="' + tsi + '">';
                            html += '<span class="va-edit-order-num">' + (tsi + 1) + '.</span>';
                            html += '<div class="va-edit-tfs-fields">';
                            html += '<textarea class="va-edit-tfs-text" rows="2" placeholder="Statement ' + (tsi + 1) + '">' + escapeHtml(stmt.text || '') + '</textarea>';
                            html += '<div class="va-edit-tfs-tf">';
                            html += '<label class="va-edit-tfs-radio"><input type="radio" name="va-tfs-' + qIdx + '-' + tsi + '" value="true"' + (stmtTrue ? ' checked' : '') + '> True</label>';
                            html += '<label class="va-edit-tfs-radio"><input type="radio" name="va-tfs-' + qIdx + '-' + tsi + '" value="false"' + (!stmtTrue ? ' checked' : '') + '> False</label>';
                            html += '</div>';
                            html += '<textarea class="va-edit-tfs-expl" rows="2" placeholder="Explanation for statement ' + (tsi + 1) + '">' + escapeHtml(stmt.explanation || '') + '</textarea>';
                            html += '</div>';
                            html += '</div>';
                        }
                        html += '</div>';
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Overall explanation (shown after all statements)">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    } else if (qtype === 'fillinblank') {
                        // BUG-VA-FIB-EDIT: FIB questions previously had no edit form  -  teachers could only delete them.
                        // Now renders passage textarea, per-blank answer inputs, and distractors field.
                        var fibText = q.text || '';
                        var fibBlanks = q.blanks || [];
                        var fibDistractors = q.distractors || [];
                        html += '<div class="va-edit-fib-section">';
                        html += '<div class="va-edit-section-label">Passage text (use ___1___, ___2___, etc. to mark blanks)</div>';
                        html += '<textarea class="va-edit-fib-passage" rows="4" placeholder="The capital of Australia is ___1___ and the largest city is ___2___.">' + escapeHtml(fibText) + '</textarea>';
                        html += '</div>';
                        if (fibBlanks.length > 0) {
                            html += '<div class="va-edit-fib-answers-section">';
                            html += '<div class="va-edit-section-label">Correct answers for each blank</div>';
                            for (var fbi = 0; fbi < fibBlanks.length; fbi++) {
                                html += '<div class="va-edit-fib-blank-row">';
                                html += '<span class="va-edit-order-num">Blank ' + (fbi + 1) + '</span>';
                                html += '<input type="text" class="va-edit-fib-answer" data-blank-idx="' + fbi + '" value="' + escapeHtml(fibBlanks[fbi].answer || '') + '" placeholder="Correct answer for blank ' + (fbi + 1) + '">';
                                html += '</div>';
                            }
                            html += '</div>';
                        }
                        html += '<div class="va-edit-fib-distractors-section">';
                        html += '<div class="va-edit-section-label">Distractor words for word bank (comma-separated)</div>';
                        html += '<input type="text" class="va-edit-fib-distractors" value="' + escapeHtml(fibDistractors.join(', ')) + '" placeholder="wrong, answer, words">';
                        html += '</div>';
                        // BUG-VA-FIB-FEEDBACK (v1.0.106): Fill in the Blank previously had no per-question
                        // feedback/explanation textarea on the Edit screen, so teachers could not author the
                        // explanatory text shown after the student completes the passage. All other question
                        // types (mcq, cardselect, categorysort, flashcards, truefalseswipe) already render an
                        // .va-edit-explanation-text textarea bound to q.explanation; FIB now matches.
                        html += '<div class="va-edit-option">';
                        html += '<textarea class="va-edit-explanation-text" rows="2" placeholder="Explanation (shown after the passage is complete)">' + escapeHtml(q.explanation || '') + '</textarea>';
                        html += '</div>';
                    }

                    qDiv.innerHTML = html;
                    container.appendChild(qDiv);
                });

                // FIX-VA-EDIT-ICON-PICKER: Live icon preview when teacher changes the icon dropdown.
                $(container).off('change', '.va-edit-card-icon').on('change', '.va-edit-card-icon', function() {
                    var selected = $(this).val();
                    var preview = $(this).closest('.va-edit-card-icon-row').find('.va-edit-icon-preview');
                    if (preview.length) {
                        preview.html(getVAIcon(selected));
                    }
                    // Also update the small icon next to the radio button
                    var optionLetter = $(this).closest('.va-edit-card-option').find('.va-option-letter');
                    if (optionLetter.length) {
                        optionLetter.html(getVAIcon(selected));
                    }
                });
            }

            function saveEdits() {
                var editedQuestions = [];
                var hasErrors = false;

                $('#va-edit-questions-container .va-edit-question').each(function(idx) {
                    var $q = $(this);
                    var questionText = $q.find('.va-edit-question-text').val().trim();
                    var qtype = $q.attr('data-type') || 'mcq';

                    if (!questionText && qtype !== 'flashcards') {
                        // BUG-VA-FLASHCARD-EDIT (v1.0.105): Flashcards have no top-level "question" field in the AI
                        // schema (only {type, cards, explanation}), so an empty stem must be allowed for that type only.
                        hasErrors = true;
                        alert('Question ' + (idx + 1) + ' text cannot be empty.');
                        return false;
                    }

                    if (qtype === 'mcq') {
                        var options = [];
                        var explanations = [];
                        var correctAnswer = 0;
                        var hasCorrectSelected = false;

                        $q.find('.va-edit-option').each(function(optIdx) {
                            var optionText = $(this).find('.va-edit-option-text').val().trim();
                            var explanationText = $(this).find('.va-edit-explanation-text').val().trim();

                            if (!optionText) {
                                hasErrors = true;
                                alert('Question ' + (idx + 1) + ', Option ' + String.fromCharCode(65 + optIdx) + ' cannot be empty.');
                                return false;
                            }

                            options.push(optionText);
                            explanations.push(explanationText);

                            if ($(this).find('input[type="radio"]').is(':checked')) {
                                correctAnswer = optIdx;
                                hasCorrectSelected = true;
                            }
                        });

                        if (hasErrors) return false;

                        if (!hasCorrectSelected) {
                            hasErrors = true;
                            alert('Question ' + (idx + 1) + ': Please select a correct answer.');
                            return false;
                        }

                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            options: options,
                            explanations: explanations,
                            correctAnswer: correctAnswer,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'cardselect') {
                        var cards = [];
                        var explanations = [];
                        var correctIndex = 0;
                        var hasCorrectCard = false;
                        var origCards = quizData[idx] ? (quizData[idx].cards || []) : [];
                        var origExpls = (quizData[idx] && Array.isArray(quizData[idx].explanations))
                            ? quizData[idx].explanations
                            : [];

                        $q.find('.va-edit-card-option').each(function(cardIdx) {
                            var label = $(this).find('.va-edit-card-label').val().trim();
                            var desc = $(this).find('.va-edit-card-desc').val().trim();

                            if (!label) {
                                hasErrors = true;
                                alert('Question ' + (idx + 1) + ', Card ' + (cardIdx + 1) + ' label cannot be empty.');
                                return false;
                            }

                            // FIX-VA-EDIT-ICON-PICKER: Read icon from the picker select element;
                            // fall back to original card icon or 'star' if picker not rendered.
                            var iconVal = $(this).find('.va-edit-card-icon').val();
                            if (!iconVal) {
                                iconVal = origCards[cardIdx] ? (origCards[cardIdx].icon || 'star') : 'star';
                            }
                            cards.push({
                                icon: iconVal,
                                label: label,
                                description: desc
                            });

                            // FIX-VA-CARDSELECT-PERCARD-EDITOR (v1.0.116): collect the per-card
                            // explanation textarea added in renderEditQuestions. Falls back to
                            // the original DB value if the textarea was somehow not rendered
                            // (defensive — should never happen post-v1.0.116) so saving never
                            // wipes existing per-card text. Pre-v1.0.116 this entire array was
                            // silently dropped on every Save, causing every wrong-card click to
                            // fall back to q.explanation (singular) — the "generic" feedback
                            // mum reported.
                            var perCardExpl = $(this).find('.va-edit-card-expl').val();
                            if (typeof perCardExpl === 'string') {
                                explanations.push(perCardExpl.trim());
                            } else {
                                explanations.push(origExpls[cardIdx] || '');
                            }

                            if ($(this).find('input[type="radio"]').is(':checked')) {
                                correctIndex = cardIdx;
                                hasCorrectCard = true;
                            }
                        });

                        if (hasErrors) return false;

                        if (!hasCorrectCard) {
                            hasErrors = true;
                            alert('Question ' + (idx + 1) + ': Please select a correct card.');
                            return false;
                        }

                        var explanation = $q.find('.va-edit-explanation-text').val().trim();

                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            cards: cards,
                            correctIndex: correctIndex,
                            explanation: explanation,
                            // FIX-VA-CARDSELECT-PERCARD-EDITOR (v1.0.116): persist the per-card
                            // explanations array so checkCardSelectAnswer at runtime can read
                            // q.explanations[selectedAnswer] and show the right card's specific
                            // feedback on a wrong click. This was the silent data loss that
                            // kept making mum's questions look "generic" — every Save wiped it.
                            explanations: explanations,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'matching') {
                        var editPairs = [];
                        var pairError = false;
                        $q.find('.va-edit-pair').each(function(pairIdx) {
                            var left = $(this).find('.va-edit-pair-left').val().trim();
                            var right = $(this).find('.va-edit-pair-right').val().trim();
                            if (!left || !right) {
                                hasErrors = true;
                                pairError = true;
                                alert('Question ' + (idx + 1) + ', Pair ' + (pairIdx + 1) + ': Both left and right values are required.');
                                return false;
                            }
                            editPairs.push({left: left, right: right});
                        });
                        if (pairError) return false;
                        var matchExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            pairs: editPairs,
                            explanation: matchExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'ordering') {
                        var orderItems = [];
                        var orderError = false;
                        $q.find('.va-edit-order-item').each(function(orderIdx) {
                            var text = $(this).find('.va-edit-order-text').val().trim();
                            if (!text) {
                                hasErrors = true;
                                orderError = true;
                                alert('Question ' + (idx + 1) + ', Item ' + (orderIdx + 1) + ' cannot be empty.');
                                return false;
                            }
                            orderItems.push(text);
                        });
                        if (orderError) return false;
                        var orderExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            items: orderItems,
                            explanation: orderExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'columnsort') {
                        var csColA = $q.find('.va-edit-col-a-name').val().trim() || 'Column A';
                        var csColB = $q.find('.va-edit-col-b-name').val().trim() || 'Column B';
                        var csEditItems = [];
                        var csError = false;
                        $q.find('.va-edit-colsort-item').each(function(csIdx) {
                            var text = $(this).find('.va-edit-colsort-text').val().trim();
                            var col = $(this).find('input[type="radio"]:checked').val();
                            if (!text) {
                                hasErrors = true;
                                csError = true;
                                alert('Question ' + (idx + 1) + ', Item ' + (csIdx + 1) + ' text cannot be empty.');
                                return false;
                            }
                            if (!col) {
                                hasErrors = true;
                                csError = true;
                                alert('Question ' + (idx + 1) + ', Item ' + (csIdx + 1) + ': Please select a column (A or B).');
                                return false;
                            }
                            csEditItems.push({text: text, column: col});
                        });
                        if (csError) return false;
                        var csExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            columnA: csColA,
                            columnB: csColB,
                            items: csEditItems,
                            explanation: csExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'categorysort') {
                        var editCats = [];
                        $q.find('.va-edit-cat-name').each(function() {
                            editCats.push($(this).val().trim() || 'Category');
                        });
                        var catEditItems = [];
                        var catError = false;
                        $q.find('.va-edit-catsort-item').each(function(catItemIdx) {
                            var text = $(this).find('.va-edit-catsort-text').val().trim();
                            var categoryIdx = parseInt($(this).find('.va-edit-catsort-select').val(), 10);
                            if (!text) {
                                hasErrors = true;
                                catError = true;
                                alert('Question ' + (idx + 1) + ', Item ' + (catItemIdx + 1) + ' text cannot be empty.');
                                return false;
                            }
                            // FIX-VA-CATSORT-EDIT-NORMALIZE (v1.0.105): Save category as the STRING CATEGORY NAME
                            // (the AI's canonical format), not the numeric index. This makes the round-trip back
                            // into the editor stable (the dropdown then re-selects via savedCat === cats[catOpt])
                            // and keeps the data shape consistent with what the AI emits on initial generation
                            // and what the regenerate-instructions prompt expects to receive back.
                            var categoryName = (editCats[categoryIdx] !== undefined) ? editCats[categoryIdx] : '';
                            catEditItems.push({text: text, category: categoryName});
                        });
                        if (catError) return false;
                        var catExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            categories: editCats,
                            items: catEditItems,
                            explanation: catExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'flashcards') {
                        // BUG-VA-FLASHCARD-EDIT (v1.0.105): Save block paired with the renderer above. Validates that
                        // both front and back are populated for every card, then persists {type, question (optional),
                        // cards: [{front, back}], explanation, audioData}.
                        var fcEditCards = [];
                        var fcError = false;
                        $q.find('.va-edit-flashcard-row').each(function(fcRowIdx) {
                            var front = $(this).find('.va-edit-fc-front').val().trim();
                            var back = $(this).find('.va-edit-fc-back').val().trim();
                            if (!front) {
                                hasErrors = true;
                                fcError = true;
                                alert('Question ' + (idx + 1) + ', Card ' + (fcRowIdx + 1) + ': Front (prompt) cannot be empty.');
                                return false;
                            }
                            if (!back) {
                                hasErrors = true;
                                fcError = true;
                                alert('Question ' + (idx + 1) + ', Card ' + (fcRowIdx + 1) + ': Back (answer) cannot be empty.');
                                return false;
                            }
                            fcEditCards.push({front: front, back: back});
                        });
                        if (fcError) return false;
                        var fcExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            cards: fcEditCards,
                            explanation: fcExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'truefalseswipe') {
                        // BUG-VA-TFS-EDIT (v1.0.105): Save block paired with the renderer above. Validates that every
                        // statement has text and an explicit True/False answer, then persists {type, question,
                        // statements: [{text, correct (bool), explanation}], explanation, audioData}.
                        var tfsEditStatements = [];
                        var tfsError = false;
                        $q.find('.va-edit-tfs-row').each(function(tfsRowIdx) {
                            var stmtText = $(this).find('.va-edit-tfs-text').val().trim();
                            var stmtCorrect = $(this).find('input[type="radio"]:checked').val();
                            var stmtExpl = $(this).find('.va-edit-tfs-expl').val().trim();
                            if (!stmtText) {
                                hasErrors = true;
                                tfsError = true;
                                alert('Question ' + (idx + 1) + ', Statement ' + (tfsRowIdx + 1) + ' text cannot be empty.');
                                return false;
                            }
                            if (stmtCorrect !== 'true' && stmtCorrect !== 'false') {
                                hasErrors = true;
                                tfsError = true;
                                alert('Question ' + (idx + 1) + ', Statement ' + (tfsRowIdx + 1) + ': Please mark it True or False.');
                                return false;
                            }
                            tfsEditStatements.push({
                                text: stmtText,
                                correct: (stmtCorrect === 'true'),
                                explanation: stmtExpl
                            });
                        });
                        if (tfsError) return false;
                        var tfsExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            statements: tfsEditStatements,
                            explanation: tfsExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else if (qtype === 'fillinblank') {
                        var fibPassage = $q.find('.va-edit-fib-passage').val().trim();
                        if (!fibPassage) {
                            hasErrors = true;
                            alert('Question ' + (idx + 1) + ': Passage text cannot be empty.');
                            return false;
                        }
                        var fibEditBlanks = [];
                        $q.find('.va-edit-fib-answer').each(function() {
                            var ans = $(this).val().trim();
                            fibEditBlanks.push({answer: ans});
                        });
                        var fibDistractorStr = $q.find('.va-edit-fib-distractors').val().trim();
                        var fibEditDistractors = fibDistractorStr
                            ? fibDistractorStr.split(',').map(function(d) { return d.trim(); }).filter(Boolean)
                            : [];
                        // BUG-VA-FIB-FEEDBACK (v1.0.106): persist the new per-question explanation textarea
                        // alongside passage/blanks/distractors so the feedback is available at runtime.
                        var fibExplanation = $q.find('.va-edit-explanation-text').val().trim();
                        editedQuestions.push({
                            type: qtype,
                            question: questionText,
                            text: fibPassage,
                            blanks: fibEditBlanks,
                            distractors: fibEditDistractors,
                            explanation: fibExplanation,
                            audioData: quizData[idx] ? quizData[idx].audioData : null
                        });
                    } else {
                        var orig = quizData[idx] || {};
                        var editedQ = JSON.parse(JSON.stringify(orig));
                        editedQ.question = questionText;
                        editedQuestions.push(editedQ);
                    }
                });

                if (hasErrors) return;

                quizData = editedQuestions;

                var $saveBtn = $('#va-save-edits-btn');
                $saveBtn.prop('disabled', true).text('Saving...');

                saveEditedQuestions(function(success) {
                    if (success) {
                        var voiceoverOn = $('#va-voiceover-toggle').is(':checked');
                        if (voiceoverOn) {
                            $saveBtn.text('Generating voiceover...');
                            regenerateAudioWithCallback(function(audioSuccess) {
                                $saveBtn.prop('disabled', false).text('Save Changes');
                                document.getElementById('va-edit-section').style.display = 'none';
                                document.getElementById('va-ready-section').style.display = 'block';
                                var summaryEl = document.getElementById('va-ready-summary');
                                if (summaryEl) {
                                    summaryEl.textContent = quizData.length + ' questions saved' + (audioSuccess ? ' with updated voiceover!' : '. Voiceover generation failed.');
                                }
                            });
                        } else {
                            $saveBtn.prop('disabled', false).text('Save Changes');
                            document.getElementById('va-edit-section').style.display = 'none';
                            document.getElementById('va-ready-section').style.display = 'block';
                            var summaryEl = document.getElementById('va-ready-summary');
                            if (summaryEl) {
                                summaryEl.textContent = quizData.length + ' questions saved successfully!';
                            }
                        }
                    } else {
                        $saveBtn.prop('disabled', false).text('Save Changes');
                        alert('Failed to save questions. Please try again.');
                    }
                });
            }

            function saveEditedQuestions(callback) {
                var questionsForDb = quizData.map(function(q) {
                    // FIX-VA-NULL-CORRECTANSWER (v1.0.130): normalise before persisting.
                    var caRaw2 = q.correctAnswer != null ? q.correctAnswer : (q.correctIndex != null ? q.correctIndex : 0);
                    var caNorm2 = parseInt(caRaw2, 10);
                    if (isNaN(caNorm2) || caNorm2 < 0) { caNorm2 = 0; }
                    var qObj = {
                        type: q.type || 'mcq',
                        question: q.question,
                        options: q.options || [],
                        explanations: q.explanations || [],
                        correctAnswer: caNorm2,
                        audioData: q.audioData || null
                    };
                    if (q.pairs) qObj.pairs = q.pairs;
                    if (q.correctOrder) qObj.correctOrder = q.correctOrder;
                    if (q.columns) qObj.columns = q.columns;
                    if (q.columnA) qObj.columnA = q.columnA;
                    if (q.columnB) qObj.columnB = q.columnB;
                    if (q.items) qObj.items = q.items;
                    if (q.categories) qObj.categories = q.categories;
                    if (q.explanation) qObj.explanation = q.explanation;
                    if (q.cards) qObj.cards = q.cards;
                    if (q.correctIndex !== undefined) qObj.correctIndex = q.correctIndex;
                    if (q.statements) qObj.statements = q.statements;
                    if (q.blanks) qObj.blanks = q.blanks;
                    if (q.text) qObj.text = q.text;
                    if (q.distractors) qObj.distractors = q.distractors;
                    // FIX-VA-TIMESTAMP-SAVE: preserve timestamp_seconds so "Show chapter
                    // timestamp links" buttons survive the DB round-trip.
                    if (q.timestamp_seconds != null) qObj.timestamp_seconds = q.timestamp_seconds;
                    return qObj;
                });

                ajaxCall('savequestions', {
                    questions: JSON.stringify(questionsForDb)
                }).done(function(response) {
                    if (response.ok) {
                        console.log('[VA] Questions saved:', response.saved);
                        callback(true);
                    } else {
                        console.error('[VA] Save failed:', response.error);
                        callback(false);
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Save request failed:', status, error);
                    callback(false);
                });
            }

            function cancelEdits() {
                if (confirm('Discard all changes?')) {
                    if (originalQuizData) {
                        quizData = originalQuizData;
                    }
                    document.getElementById('va-edit-section').style.display = 'none';
                    document.getElementById('va-ready-section').style.display = 'block';
                }
            }

            // ==========================================
            // TEACHER: REGENERATION
            // ==========================================

            function updateRegenCountDisplay() {
                var remaining = Math.max(0, 3 - regenerationCount);
                var countText = '';
                if (regenerationCount === 0) {
                    countText = '3 free regenerations remaining';
                } else if (remaining > 0) {
                    countText = remaining + ' free regeneration' + (remaining !== 1 ? 's' : '') + ' remaining';
                } else {
                    countText = 'Free regenerations used. Next regeneration will use credits.';
                }
                var el = document.getElementById('va-ready-regen-count');
                if (el) el.textContent = countText;
                var el2 = document.getElementById('va-edit-regen-count');
                if (el2) el2.textContent = countText;
            }

            function handleRegenerate() {
                var extraInstructions = $('#va-ready-extra-instructions').val() || '';
                var $btn = $('#va-ready-regenerate-btn');

                if (!quizData || quizData.length === 0) {
                    alert('No questions to regenerate. Please generate questions first.');
                    return;
                }

                var isFree = regenerationCount < 3;
                if (!isFree) {
                    var voiceoverOn = $('#va-voiceover-toggle').is(':checked');
                    var creditsNeeded = voiceoverOn ? quizData.length * 2 : quizData.length;
                    if (!confirm('You have used all 3 free regenerations.\n\nThis regeneration will cost ' + creditsNeeded + ' credits.\n\nDo you want to continue?')) {
                        return;
                    }
                }

                var origHtml = $btn.html();
                $btn.prop('disabled', true).text('Regenerating...');

                // FIX-VA-REGEN-BATCH (v1.0.89): Replace slow sequential per-question requests
                // with a single batch call. The server calls Gemini once for ALL questions —
                // sending them one-at-a-time multiplied latency and caused "Q{n} busy — retrying…"
                // stalls (each retry waited 10 seconds). A batch call is faster and simpler.
                var voiceoverEnabled = $('#va-voiceover-toggle').is(':checked') ? 1 : 0;
                var total = quizData.length;

                $btn.prop('disabled', true).text('Regenerating ' + total + ' question' + (total !== 1 ? 's' : '') + '\u2026');

                // FIX-VA-REGEN-TIMESTAMP (v1.0.94): Include timestamp_seconds in the payload
                // so the server preservation branch can run. Without it the server receives
                // undefined and skips the copy, dropping Jump-to links after batch regeneration.
                // FIX-VA-REGEN-TYPEFIELDS (v1.0.98): Previously the payload stripped every
                // type-specific field (pairs, cards, items, categories, columns, correctOrder,
                // explanation, blanks). The server's non-MCQ preserve branch then copied the
                // stripped originalQ back into quizData, silently destroying matching pairs,
                // cardselect/T-F explanations, sort items, fill-in-blank slots, etc. Q1 looked
                // fine because it happened to be MCQ; Q2-Q5 lost structure. Spread q0 first so
                // every original field survives the round-trip, then layer the regen-specific
                // overrides on top.
                var allReadyQuestions = quizData.map(function(q0) {
                    return Object.assign({}, q0, {
                        id: q0.id, type: q0.type || 'mcq', question: q0.question,
                        options: q0.options, explanations: q0.explanations,
                        correctIndex: q0.correctIndex !== undefined ? q0.correctIndex : (q0.correctAnswer || 0),
                        mappingTopic: q0.mappingTopic || '', mappingCriteria: q0.mappingCriteria || '',
                        timestamp_seconds: q0.timestamp_seconds != null ? q0.timestamp_seconds : null,
                        audioData: undefined
                    });
                });

                function doReadyBatch(retriesLeft) {
                    ajaxCall('regenerateinstructions', {
                        questions: JSON.stringify(allReadyQuestions),
                        extraInstructions: extraInstructions,
                        voiceLanguage: $('#va-voice-language').val() || 'en-AU',
                        voiceoverEnabled: voiceoverEnabled,
                        voiceGender: $('#va-voice-gender').val() || 'female',
                        voiceId: $('#va-voice-style').val() || 'Aoede'
                    }, 180000).done(function(response) {
                        if (response.ok && response.jobId) {
                            // FIX-VA-REGEN-ASYNC (v1.0.90): async job started — poll for results.
                            $btn.text('Regenerating\u2026 0%');
                            pollRegenJob(response.jobId,
                                function(pct) { $btn.text('Regenerating\u2026 ' + pct + '%'); },
                                function(questions) {
                                    quizData = questions;
                                    regenerationCount++;
                                    updateRegenCountDisplay();
                                    saveQuestionsToDatabase();
                                    fetchCredits();
                                    var summaryEl = document.getElementById('va-ready-summary');
                                    if (summaryEl) { summaryEl.textContent = total + ' questions regenerated!'; }
                                    $btn.prop('disabled', false).html(origHtml);
                                },
                                function(error) {
                                    $btn.prop('disabled', false).html(origHtml);
                                    alert('Regeneration failed: ' + error + '\n\nPlease try again.');
                                }
                            );
                        } else if (response.ok && response.questions && response.questions.length > 0) {
                            quizData = response.questions;
                            regenerationCount++;
                            updateRegenCountDisplay();
                            saveQuestionsToDatabase();
                            fetchCredits();
                            var summaryEl = document.getElementById('va-ready-summary');
                            if (summaryEl) { summaryEl.textContent = total + ' questions regenerated!'; }
                            $btn.prop('disabled', false).html(origHtml);
                        } else {
                            if (retriesLeft > 0) {
                                $btn.text('Retrying\u2026');
                                setTimeout(function() { doReadyBatch(retriesLeft - 1); }, 2000);
                            } else {
                                $btn.prop('disabled', false).html(origHtml);
                                alert('Regeneration failed: ' + (response.error || 'Unknown error') + '\n\nPlease try again.');
                            }
                        }
                    }).fail(function() {
                        if (retriesLeft > 0) {
                            $btn.text('Retrying\u2026');
                            setTimeout(function() { doReadyBatch(retriesLeft - 1); }, 2000);
                        } else {
                            $btn.prop('disabled', false).html(origHtml);
                            alert('Regeneration failed (connection error). Please try again.');
                        }
                    });
                }
                doReadyBatch(1);
            }

            // FIX-VA-EDIT-REGEN (v1.0.71): handleEditRegenerate - wires up the "Regenerate Questions"
            // button that lives inside the Edit Questions section. Previously this button had no
            // event binding so clicking it did nothing. Reads from #va-edit-extra-instructions,
            // updates #va-edit-regenerate-btn, and rebuilds the edit form on success.
            function handleEditRegenerate() {
                var extraInstructions = $('#va-edit-extra-instructions').val() || '';
                var $btn = $('#va-edit-regenerate-btn');

                if (!quizData || quizData.length === 0) {
                    alert('No questions to regenerate. Please generate questions first.');
                    return;
                }

                var isFree = regenerationCount < 3;
                if (!isFree) {
                    var voiceoverOn = $('#va-voiceover-toggle').is(':checked');
                    var creditsNeeded = voiceoverOn ? quizData.length * 2 : quizData.length;
                    if (!confirm('You have used all 3 free regenerations.\n\nThis regeneration will cost ' + creditsNeeded + ' credits.\n\nDo you want to continue?')) {
                        return;
                    }
                }

                var origHtml = $btn.html();
                $btn.prop('disabled', true).text('Regenerating...');

                // FIX-VA-REGEN-BATCH (v1.0.89): Same batch fix as handleRegenerate above.
                // Single call for all questions — eliminates per-question delays and busy-retries.
                var voiceoverEnabled = $('#va-voiceover-toggle').is(':checked') ? 1 : 0;
                var total = quizData.length;

                $btn.prop('disabled', true).text('Regenerating ' + total + ' question' + (total !== 1 ? 's' : '') + '\u2026');

                // FIX-VA-REGEN-TIMESTAMP (v1.0.94): Same as allReadyQuestions above —
                // include timestamp_seconds so server preserves Jump-to links in Edit view.
                // FIX-VA-REGEN-TYPEFIELDS (v1.0.98): Same fix as handleRegenerate above —
                // spread q0 first so non-MCQ type-specific fields (pairs, cards, items,
                // categories, columns, correctOrder, explanation, blanks) survive the
                // round-trip and the server's preserve branch returns the full original.
                var allEditQuestions = quizData.map(function(q0) {
                    return Object.assign({}, q0, {
                        id: q0.id, type: q0.type || 'mcq', question: q0.question,
                        options: q0.options, explanations: q0.explanations,
                        correctIndex: q0.correctIndex !== undefined ? q0.correctIndex : (q0.correctAnswer || 0),
                        mappingTopic: q0.mappingTopic || '', mappingCriteria: q0.mappingCriteria || '',
                        timestamp_seconds: q0.timestamp_seconds != null ? q0.timestamp_seconds : null,
                        audioData: undefined
                    });
                });

                function doEditBatch(retriesLeft) {
                    ajaxCall('regenerateinstructions', {
                        questions: JSON.stringify(allEditQuestions),
                        extraInstructions: extraInstructions,
                        voiceLanguage: $('#va-voice-language').val() || 'en-AU',
                        voiceoverEnabled: voiceoverEnabled,
                        voiceGender: $('#va-voice-gender').val() || 'female',
                        voiceId: $('#va-voice-style').val() || 'Aoede'
                    }, 180000).done(function(response) {
                        if (response.ok && response.jobId) {
                            // FIX-VA-REGEN-ASYNC (v1.0.90): async job started — poll for results.
                            $btn.text('Regenerating\u2026 0%');
                            pollRegenJob(response.jobId,
                                function(pct) { $btn.text('Regenerating\u2026 ' + pct + '%'); },
                                function(questions) {
                                    quizData = questions;
                                    regenerationCount++;
                                    updateRegenCountDisplay();
                                    saveQuestionsToDatabase();
                                    fetchCredits();
                                    buildEditForms();
                                    var editSummaryEl = document.getElementById('va-edit-summary');
                                    if (editSummaryEl) {
                                        editSummaryEl.textContent = total + ' questions regenerated successfully!';
                                        setTimeout(function() { editSummaryEl.textContent = ''; }, 5000);
                                    }
                                    $btn.prop('disabled', false).html(origHtml);
                                },
                                function(error) {
                                    $btn.prop('disabled', false).html(origHtml);
                                    alert('Regeneration failed: ' + error + '\n\nPlease try again.');
                                }
                            );
                        } else if (response.ok && response.questions && response.questions.length > 0) {
                            quizData = response.questions;
                            regenerationCount++;
                            updateRegenCountDisplay();
                            saveQuestionsToDatabase();
                            fetchCredits();
                            buildEditForms();
                            var editSummaryEl = document.getElementById('va-edit-summary');
                            if (editSummaryEl) {
                                editSummaryEl.textContent = total + ' questions regenerated successfully!';
                                setTimeout(function() { editSummaryEl.textContent = ''; }, 5000);
                            }
                            $btn.prop('disabled', false).html(origHtml);
                        } else {
                            if (retriesLeft > 0) {
                                $btn.text('Retrying\u2026');
                                setTimeout(function() { doEditBatch(retriesLeft - 1); }, 2000);
                            } else {
                                $btn.prop('disabled', false).html(origHtml);
                                alert('Regeneration failed: ' + (response.error || 'Unknown error') + '\n\nPlease try again.');
                            }
                        }
                    }).fail(function() {
                        if (retriesLeft > 0) {
                            $btn.text('Retrying\u2026');
                            setTimeout(function() { doEditBatch(retriesLeft - 1); }, 2000);
                        } else {
                            $btn.prop('disabled', false).html(origHtml);
                            alert('Regeneration failed (connection error). Please try again.');
                        }
                    });
                }
                doEditBatch(1);
            }

            // FIX-VA-PER-QUESTION-REGEN (v1.0.71): handleSingleRegenerate - regenerates a single
            // question at index qIdx. Sends only that one question to the server so the AI focuses
            // on it; replaces just that entry in quizData and rebuilds the edit form.
            function handleSingleRegenerate(qIdx, $btn) {
                if (!quizData || qIdx < 0 || qIdx >= quizData.length) return;

                var isFree = regenerationCount < 3;
                if (!isFree) {
                    var voiceoverOn = $('#va-voiceover-toggle').is(':checked');
                    var creditsNeeded = voiceoverOn ? 2 : 1;
                    if (!confirm('You have used all 3 free regenerations.\n\nRegenerating this question will cost ' + creditsNeeded + ' credit' + (creditsNeeded !== 1 ? 's' : '') + '.\n\nDo you want to continue?')) {
                        return;
                    }
                }

                var origHtml = $btn.html();
                $btn.prop('disabled', true).text('Regenerating...');

                var singleQuestion = [quizData[qIdx]];
                var extraInstructions = $('#va-edit-extra-instructions').val() || '';
                var voiceoverEnabled = $('#va-voiceover-toggle').is(':checked') ? 1 : 0;

                var singleRegenParams = {
                    questions: JSON.stringify(singleQuestion),
                    extraInstructions: extraInstructions,
                    voiceLanguage: $('#va-voice-language').val() || 'en-AU',
                    voiceoverEnabled: voiceoverEnabled,
                    voiceGender: $('#va-voice-gender').val() || 'female',
                    voiceId: $('#va-voice-style').val() || 'Aoede'
                };

                // FIX-VA-REGEN-RETRY (v1.0.79): Add retry logic — up to 3 total attempts.
                // FIX-VA-REGEN-ASYNC (v1.0.90): Also handle async jobId response.
                function doSingleRequest(attemptsLeft) {
                    ajaxCall('regenerateinstructions', singleRegenParams, 120000).done(function(response) {
                        if (response.ok && response.jobId) {
                            // Async job — poll until complete.
                            $btn.text('Regenerating\u2026 0%');
                            pollRegenJob(response.jobId,
                                function(pct) { $btn.text('Regenerating\u2026 ' + pct + '%'); },
                                function(questions) {
                                    regenerationCount++;
                                    quizData[qIdx] = questions[0];
                                    saveQuestionsToDatabase();
                                    updateRegenCountDisplay();
                                    fetchCredits();
                                    buildEditForms();
                                },
                                function(error) {
                                    alert('Regeneration failed: ' + error);
                                    $btn.prop('disabled', false).html(origHtml);
                                }
                            );
                        } else if (response.ok && response.questions && response.questions.length > 0) {
                            regenerationCount++;
                            quizData[qIdx] = response.questions[0];
                            saveQuestionsToDatabase();
                            updateRegenCountDisplay();
                            fetchCredits();
                            buildEditForms();
                            // buildEditForms() recreates the DOM so no need to re-enable $btn
                        } else {
                            var isBusy = response.error && (
                                response.error.toLowerCase().indexOf('busy') !== -1 ||
                                response.error.toLowerCase().indexOf('rate limit') !== -1 ||
                                response.error.toLowerCase().indexOf('temporarily') !== -1
                            );
                            if (isBusy && attemptsLeft > 0) {
                                $btn.text('Retrying\u2026');
                                setTimeout(function() { doSingleRequest(attemptsLeft - 1); }, 5000);
                            } else {
                                alert(response.error || 'Regeneration failed');
                                $btn.prop('disabled', false).html(origHtml);
                            }
                        }
                    }).fail(function() {
                        if (attemptsLeft > 0) {
                            $btn.text('Retrying\u2026');
                            setTimeout(function() { doSingleRequest(attemptsLeft - 1); }, 5000);
                        } else {
                            alert('Request failed. Please try again.');
                            $btn.prop('disabled', false).html(origHtml);
                        }
                    });
                }
                doSingleRequest(2);
            }

            // ==========================================
            // TEACHER: REGENERATE AUDIO
            // ==========================================

            function regenerateAudio() {
                var voiceLanguage = $('#va-voice-language').val() || 'en-AU';
                var voiceId = $('#va-voice-style').val() || 'Aoede';

                $('#va-regenerate-audio-btn').prop('disabled', true).text('Generating Audio...');

                var questionsForApi = quizData.map(function(q) {
                    // FIX-VA-NULL-CORRECTANSWER (v1.0.130): normalise before API call.
                    var caRawApi = q.correctAnswer != null ? q.correctAnswer : (q.correctIndex != null ? q.correctIndex : 0);
                    var caNormApi = parseInt(caRawApi, 10);
                    if (isNaN(caNormApi) || caNormApi < 0) { caNormApi = 0; }
                    var qObj = {
                        id: q.id,
                        type: q.type || 'mcq',
                        question: q.question,
                        options: q.options,
                        explanations: q.explanations,
                        correctAnswer: caNormApi,
                        explanation: q.explanation || ''
                    };
                    if (q.cards) qObj.cards = q.cards;
                    if (q.pairs) qObj.pairs = q.pairs;
                    if (q.columns) qObj.columns = q.columns;
                    if (q.items) qObj.items = q.items;
                    if (q.categories) qObj.categories = q.categories;
                    return qObj;
                });

                ajaxCall('regenerateaudio', {
                    questions: JSON.stringify(questionsForApi),
                    voiceLanguage: voiceLanguage,
                    voiceId: voiceId
                }).done(function(response) {
                    if (response.ok && response.questions) {
                        for (var i = 0; i < response.questions.length; i++) {
                            if (quizData[i] && response.questions[i].audioData) {
                                quizData[i].audioData = response.questions[i].audioData;
                            }
                        }
                        saveQuestionsToDatabase();
                        $('#va-regenerate-audio-btn').remove();
                        var summaryEl = document.getElementById('va-ready-summary');
                        if (summaryEl) {
                            summaryEl.textContent = quizData.length + ' questions ready with voiceover audio!';
                        }
                        alert('Audio generated successfully!');
                    } else {
                        alert('Failed to generate audio: ' + (response.error || 'Unknown error'));
                        $('#va-regenerate-audio-btn').prop('disabled', false).text('Generate Audio');
                    }
                }).fail(function() {
                    alert('Failed to generate audio. Please try again.');
                    $('#va-regenerate-audio-btn').prop('disabled', false).text('Generate Audio');
                });
            }

            function regenerateAudioWithCallback(callback) {
                var voiceLanguage = $('#va-voice-language').val() || 'en-AU';
                var voiceId = $('#va-voice-style').val() || 'Aoede';

                var questionsForApi = quizData.map(function(q) {
                    // FIX-VA-NULL-CORRECTANSWER (v1.0.130): normalise before API call.
                    var caRawApi = q.correctAnswer != null ? q.correctAnswer : (q.correctIndex != null ? q.correctIndex : 0);
                    var caNormApi = parseInt(caRawApi, 10);
                    if (isNaN(caNormApi) || caNormApi < 0) { caNormApi = 0; }
                    var qObj = {
                        id: q.id,
                        type: q.type || 'mcq',
                        question: q.question,
                        options: q.options,
                        explanations: q.explanations,
                        correctAnswer: caNormApi,
                        explanation: q.explanation || ''
                    };
                    if (q.cards) qObj.cards = q.cards;
                    if (q.pairs) qObj.pairs = q.pairs;
                    if (q.columns) qObj.columns = q.columns;
                    if (q.items) qObj.items = q.items;
                    if (q.categories) qObj.categories = q.categories;
                    return qObj;
                });

                $.ajax({
                    url: config.wwwroot + '/mod/aivideoactivity/ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'regenerateaudio',
                        sesskey: config.sesskey,
                        cmid: config.cmid,
                        questions: JSON.stringify(questionsForApi),
                        voiceLanguage: voiceLanguage,
                        voiceId: voiceId
                    },
                    timeout: 120000,
                    success: function(response) {
                        if (response.ok && response.questions) {
                            for (var i = 0; i < response.questions.length; i++) {
                                if (quizData[i] && response.questions[i].audioData) {
                                    quizData[i].audioData = response.questions[i].audioData;
                                }
                                // FIX-VA-CARDSELECT-LEGACY-AUTOUPGRADE (v1.0.112): the
                                // server now returns explanations[] for legacy cardselect
                                // questions that it just auto-upgraded to per-card format.
                                // Copy the array back so subsequent attempts use per-card
                                // text + audio. Persisted via saveEditedQuestions below.
                                if (quizData[i] && response.questions[i].type === 'cardselect' &&
                                    Array.isArray(response.questions[i].explanations) &&
                                    response.questions[i].explanations.length > 0) {
                                    quizData[i].explanations = response.questions[i].explanations;
                                }
                            }
                            saveEditedQuestions(function(saveSuccess) {
                                callback(saveSuccess);
                            });
                        } else {
                            callback(false);
                        }
                    },
                    error: function() {
                        callback(false);
                    }
                });
            }

            // ==========================================
            // TEACHER: SETTINGS MODAL
            // ==========================================

            function openSettingsModal() {
                console.log('[VA] Opening settings modal');
                $('#va-settings-voice-language').val($('#va-voice-language').val() || 'en-AU');
                var voiceoverEnabled = $('#va-voiceover-toggle').is(':checked');
                $('#va-settings-voiceover-toggle').prop('checked', voiceoverEnabled);
                if (voiceoverEnabled) {
                    document.getElementById('va-settings-voice-options').style.display = 'block';
                } else {
                    document.getElementById('va-settings-voice-options').style.display = 'none';
                }

                var currentGender = $('#va-voice-gender').val() || 'female';
                var currentStyle = $('#va-voice-style').val() || 'Aoede';
                $('#va-settings-voice-gender').val(currentGender);
                handleGenderChange('va-settings');
                setTimeout(function() {
                    $('#va-settings-voice-style').val(currentStyle);
                }, 50);

                document.getElementById('va-settings-overlay').style.display = 'block';
            }

            function closeSettingsModal() {
                document.getElementById('va-settings-overlay').style.display = 'none';
            }

            function saveSettings() {
                console.log('[VA] Saving settings');
                var newLanguage = $('#va-settings-voice-language').val();
                var newVoiceoverEnabled = $('#va-settings-voiceover-toggle').is(':checked');
                var newGender = $('#va-settings-voice-gender').val();
                var newStyle = $('#va-settings-voice-style').val();

                var oldVoiceoverEnabled = $('#va-voiceover-toggle').is(':checked');

                $('#va-voice-language').val(newLanguage);
                $('#va-voiceover-toggle').prop('checked', newVoiceoverEnabled);
                $('#va-voice-gender').val(newGender);
                handleGenderChange('va');
                setTimeout(function() {
                    $('#va-voice-style').val(newStyle);
                }, 50);

                closeSettingsModal();

                ajaxCall('savevoicesettings', {
                    voiceoverEnabled: newVoiceoverEnabled ? 1 : 0,
                    voiceLanguage: newLanguage,
                    voiceGender: newGender,
                    voiceStyle: newStyle
                }).done(function() {
                    console.log('[VA] Voice settings saved to database');
                }).fail(function() {
                    console.error('[VA] Failed to save voice settings');
                });

                if (oldVoiceoverEnabled && !newVoiceoverEnabled && quizData) {
                    for (var i = 0; i < quizData.length; i++) {
                        quizData[i].audioData = null;
                    }
                    saveEditedQuestions(function() {});
                } else if (!oldVoiceoverEnabled && newVoiceoverEnabled && quizData) {
                    regenerateAudioWithCallback(function(success) {
                        if (success) {
                            console.log('[VA] Voiceover audio generated');
                        }
                    });
                }
            }

            // ==========================================
            // TEACHER: QUIZ PREVIEW
            // ==========================================

            function startTeacherPreview() {
                if (!quizData || quizData.length === 0) {
                    alert('No questions to preview.');
                    return;
                }

                currentQuestionIndex = 0;
                score = 0;
                scoredQuestionIndices = {};
                selectedAnswer = null;
                selectedLabel = null;

                document.getElementById('va-ready-section').style.display = 'none';
                document.getElementById('va-quiz-player').style.display = 'block';

                showQuestion();
            }

            // ==========================================
            // STUDENT: START / CONTINUE ATTEMPT
            // ==========================================

            function handleStartAttempt() {
                console.log('[VA] Starting new attempt');
                if (config.mediaType === 'audio') {
                    var audioEl = document.getElementById('va-audio-player');
                    if (audioEl) audioEl.pause();
                } else if (ytPlayer && ytPlayer.pauseVideo) {
                    ytPlayer.pauseVideo();
                }
                var btn = document.getElementById('va-start-quiz-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Loading...';
                }

                ajaxCall('startattempt').done(function(response) {
                    if (response.ok) {
                        currentAttemptId = response.attemptid;
                        console.log('[VA] Attempt started:', currentAttemptId);
                        loadQuestionsFromDatabase();
                    } else {
                        alert(response.error || 'Failed to start attempt');
                        if (btn) {
                            btn.disabled = false;
                            // FIX-VA-RETAKE-LOADING-BTN (v1.0.109): restore HTML (SVG + label)
                            // not just text, so the icon survives the failure path.
                            resetStartQuizButtonHtml(!!config.hasPreviousAttempts);
                        }
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Start attempt failed:', status, error);
                    alert('Failed to start quiz. Please try again.');
                    if (btn) {
                        btn.disabled = false;
                        resetStartQuizButtonHtml(!!config.hasPreviousAttempts);
                    }
                });
            }

            function handleContinueAttempt() {
                console.log('[VA] Continuing attempt');
                var btn = document.getElementById('va-continue-attempt-btn');
                currentAttemptId = btn ? btn.getAttribute('data-attemptid') : null;
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Loading...';
                }
                loadQuestionsFromDatabase();
            }

            // ==========================================
            // STUDENT: LOAD QUESTIONS & SHUFFLE
            // ==========================================

            function getShuffledIndices(length) {
                var indices = [];
                for (var i = 0; i < length; i++) {
                    indices.push(i);
                }
                for (var j = length - 1; j > 0; j--) {
                    var k = Math.floor(Math.random() * (j + 1));
                    var temp = indices[j];
                    indices[j] = indices[k];
                    indices[k] = temp;
                }
                return indices;
            }

            function loadQuestionsFromDatabase() {
                console.log('[VA] Loading questions from database');

                ajaxCall('getquestions').done(function(response) {
                    if (response.ok && response.questions && response.questions.length > 0) {
                        console.log('[VA] Loaded questions:', response.questions.length);

                        // FIX-VA-VOICEOVER-DIAG (v1.0.103): log audioData status as
                        // returned from getquestions so we can confirm whether the DB
                        // round-trip preserved voiceover bytes.
                        response.questions.forEach(function(dq, di) {
                            var ad = dq && dq.audioData;
                            var adLen = Array.isArray(ad) ? ad.length : (ad ? 1 : 0);
                            var firstLen = Array.isArray(ad) && ad[0] ? ad[0].length : 0;
                            console.log('[VA-DIAG] load Q' + (di + 1) + ' type=' + (dq && dq.type) +
                                ' audioData=' + (ad === null ? 'null' : (ad === undefined ? 'undefined' : (Array.isArray(ad) ? 'array' : typeof ad))) +
                                ' len=' + adLen + ' firstB64=' + firstLen);
                        });

                        quizData = response.questions.map(function(q) {
                            var qtype = q.type || 'mcq';

                            if (qtype === 'cardselect') {
                                var cards = q.cards || [];
                                var cardCount = cards.length;
                                var shuffledCardIndices = getShuffledIndices(cardCount);
                                var shuffledCards = [];
                                var newCorrectCardIdx = 0;
                                var origCorrectIdx = q.correctIndex !== undefined ? parseInt(q.correctIndex, 10) : 0;

                                // FIX-VA-CARDSELECT-PERCARD-VOICEOVER (v1.0.110): cardselect now supports
                                // per-card explanations + per-card audio (one explanation/clip per card)
                                // so the player can show the explanation for the card the student actually
                                // picked instead of always showing the correct card's explanation. Shuffle
                                // explanations[] and audioData[] positionally with the cards so that
                                // shuffledExplanations[displayIdx] === origExplanations[shuffledCardIndices[displayIdx]].
                                // Legacy questions only have q.explanation (singular) and q.audioData=[oneClip] —
                                // shuffledExplanations stays null and audioData passes through unchanged so the
                                // player falls back to the v1.0.109 single-clip behaviour for those.
                                var origExplanations = (Array.isArray(q.explanations) && q.explanations.length === cardCount) ? q.explanations.slice() : null;
                                var origAudioData = (Array.isArray(q.audioData) && q.audioData.length === cardCount) ? q.audioData.slice() : null;

                                // FIX-VA-CARDSELECT-CLIENTSIDE-ALIGN (v1.0.125): apply the same
                                // alignment that server/routes.ts fixCardSelectExplanationOrder()
                                // performs, but here on the CLIENT side, before shuffling.
                                //
                                // WHY: fixCardSelectExplanationOrder() only runs at generate/regen
                                // time on the AI Grader server. Questions already stored in Moodle
                                // DBs that were generated BEFORE that fix was introduced arrive here
                                // with the raw AI output — "Correct." text/audio at slot 0 regardless
                                // of correctIndex. After a shuffle the per-card indices stay aligned
                                // with each other, but the WRONG slot plays "Correct." audio.
                                // Example: correctIndex=2, audioData[0]="Correct." audio →
                                // student picks card 2 (right one), hears "Incorrect." voiceover.
                                //
                                // Pass 1: move the "Correct." explanation (and its matching audio)
                                //         to origCorrectIdx if it isn't already there.
                                if (origExplanations &&
                                    origCorrectIdx >= 0 &&
                                    origCorrectIdx < origExplanations.length &&
                                    !(origExplanations[origCorrectIdx] || '').trim().toLowerCase().startsWith('correct')) {
                                    var correctSlot = -1;
                                    for (var ali = 0; ali < origExplanations.length; ali++) {
                                        if ((origExplanations[ali] || '').trim().toLowerCase().startsWith('correct')) {
                                            correctSlot = ali;
                                            break;
                                        }
                                    }
                                    if (correctSlot !== -1) {
                                        var tmpExpl = origExplanations[correctSlot];
                                        origExplanations[correctSlot] = origExplanations[origCorrectIdx];
                                        origExplanations[origCorrectIdx] = tmpExpl;
                                        if (origAudioData) {
                                            var tmpAud = origAudioData[correctSlot];
                                            origAudioData[correctSlot] = origAudioData[origCorrectIdx];
                                            origAudioData[origCorrectIdx] = tmpAud;
                                        }
                                        console.log('[VA-DIAG] clientAlign: moved Correct. expl from slot ' + correctSlot + ' to correctIdx ' + origCorrectIdx);
                                    }
                                }
                                // Pass 1.5: FIX-VA-CARDSELECT-AUDIO-LENGTH-ALIGN (v1.0.127):
                                // Detect the case where the server previously fixed explanation TEXT
                                // order (explanations[correctIndex] already starts with "Correct."
                                // so Pass 1 above correctly skipped) but did NOT fix audioData order.
                                // In this state audioData[0] still holds the "Correct." TTS clip while
                                // audioData[correctIndex] holds an "Incorrect." clip — the opposite of
                                // what the shuffle expects.
                                //
                                // Detection heuristic: the "Correct. [detailed explanation]" TTS clip
                                // is reliably longer than an "Incorrect. [short label] isn't right"
                                // clip, so comparing base64 string lengths (proportional to audio
                                // byte length) gives a sound proxy. If origAudioData[0] is 10%+
                                // longer than origAudioData[origCorrectIdx] AND origCorrectIdx != 0,
                                // swap them — audio[0] is almost certainly the misaligned "Correct."
                                // narration. For correctly-ordered questions origAudioData[correctIdx]
                                // IS the longest clip so the comparison returns false and no swap
                                // occurs, leaving well-formed questions untouched.
                                //
                                // FIX-VA-CARDSELECT-AUDIO-NOEXPL-ALIGN (v1.0.128): removed the
                                // `&& origExplanations` gate from this block.
                                //
                                // Previously Pass 1.5 only ran when per-card explanations existed.
                                // But questions can have per-card audio (audioData.length === cards.length)
                                // with NO per-card explanations (origExplanations === null) — the teacher
                                // regenerated audio without regenerating explanations, or the question was
                                // stored in a state where explanations were missing/mismatched.
                                // In that case origExplanations is null, the gate skipped Pass 1.5
                                // entirely, and the AI's "Correct." TTS clip remained at slot 0 regardless
                                // of correctIndex. After the card shuffle the correct card received the
                                // wrong audio clip → student hears "Incorrect." voiceover despite selecting
                                // the right card.
                                //
                                // Fix: run the length heuristic whenever:
                                //   A) origExplanations is null (no per-card text to check) — use audio
                                //      length alone as the proxy; OR
                                //   B) origExplanations exists AND explanations[correctIdx] already
                                //      starts with "Correct." (Pass 1 skipped, text OK, audio may not be)
                                if (origAudioData && origCorrectIdx !== 0) {
                                    var shouldCheckAudioAlign = !origExplanations ||
                                        (origExplanations[origCorrectIdx] || '').trim().toLowerCase().startsWith('correct');
                                    if (shouldCheckAudioAlign) {
                                        var aud0 = origAudioData[0] || '';
                                        var audCidx = origAudioData[origCorrectIdx] || '';
                                        if (aud0.length > 0 && audCidx.length > 0 && aud0.length > audCidx.length * 1.1) {
                                            var tmpAud15 = origAudioData[0];
                                            origAudioData[0] = origAudioData[origCorrectIdx];
                                            origAudioData[origCorrectIdx] = tmpAud15;
                                            console.log('[VA-DIAG] clientAlign Pass1.5: swapped audio[0]<->audio[' + origCorrectIdx + '] via length heuristic (aud0=' + aud0.length + ' audCidx=' + audCidx.length + ')');
                                        }
                                    }
                                }

                                // Pass 2: sanitise any wrong-card slot that still starts with "Correct."
                                // (can happen if AI generated multiple Correct. entries).
                                if (origExplanations) {
                                    for (var ali2 = 0; ali2 < origExplanations.length; ali2++) {
                                        if (ali2 === origCorrectIdx) continue;
                                        if ((origExplanations[ali2] || '').trim().toLowerCase().startsWith('correct')) {
                                            var cardLbl = (cards[ali2] && cards[ali2].label) ? String(cards[ali2].label).trim() : 'This option';
                                            var body2 = origExplanations[ali2].replace(/^correct[.:!]?\s*/i, '').trim();
                                            origExplanations[ali2] = 'Incorrect. ' + (body2 || cardLbl + " isn't the right answer for this question.");
                                            console.log('[VA-DIAG] clientAlign: sanitised Correct. at wrong-card slot ' + ali2);
                                        }
                                    }
                                }

                                var shuffledExplanations = origExplanations ? [] : null;
                                var shuffledAudioData = origAudioData ? [] : null;

                                for (var ci = 0; ci < cardCount; ci++) {
                                    var origIdx = shuffledCardIndices[ci];
                                    shuffledCards.push(cards[origIdx]);
                                    if (origExplanations) { shuffledExplanations.push(origExplanations[origIdx] || ''); }
                                    if (origAudioData) { shuffledAudioData.push(origAudioData[origIdx] || ''); }
                                    if (origIdx === origCorrectIdx) {
                                        newCorrectCardIdx = ci;
                                    }
                                }

                                return {
                                    id: q.id,
                                    type: 'cardselect',
                                    question: q.question,
                                    cards: shuffledCards,
                                    correctIndex: newCorrectCardIdx,
                                    explanation: q.explanation || '',
                                    explanations: shuffledExplanations,
                                    audioData: shuffledAudioData !== null ? shuffledAudioData : (q.audioData || null),
                                    timestamp_seconds: q.timestamp_seconds != null ? q.timestamp_seconds : null
                                };
                            }

                            if (qtype === 'mcq') {
                                var opts = q.options || [];
                                var expls = q.explanations || [];
                                var optCount = opts.length;
                                var shuffledIndices = getShuffledIndices(optCount);
                                var shuffledOptions = [];
                                var shuffledExplanations = [];
                                var shuffledAudioData = q.audioData ? [] : null;
                                var shuffledToOriginal = [];
                                var newCorrectIndex = 0;

                                // FIX-VA-NULL-CORRECTANSWER (v1.0.130): use != null (covers both
                                // null AND undefined) so parseInt(null)=NaN no longer silently
                                // defaults newCorrectIndex to 0 (which caused the first displayed
                                // option to always appear correct after shuffling whenever the AI
                                // returned correctAnswer:null or the field was absent).
                                // Compute once outside the loop for efficiency.
                                var mcqOrigCorrectIdx = q.correctAnswer != null
                                    ? parseInt(q.correctAnswer, 10)
                                    : (q.correctIndex != null ? parseInt(q.correctIndex, 10) : 0);
                                if (isNaN(mcqOrigCorrectIdx) || mcqOrigCorrectIdx < 0 || mcqOrigCorrectIdx >= optCount) {
                                    mcqOrigCorrectIdx = 0;
                                }

                                for (var i = 0; i < optCount; i++) {
                                    var origIndex = shuffledIndices[i];
                                    shuffledOptions.push(opts[origIndex] || '');
                                    shuffledExplanations.push(expls[origIndex] || '');
                                    shuffledToOriginal.push(origIndex);
                                    if (shuffledAudioData && q.audioData && q.audioData[origIndex]) {
                                        shuffledAudioData.push(q.audioData[origIndex]);
                                    } else if (shuffledAudioData) {
                                        shuffledAudioData.push(null);
                                    }
                                    if (origIndex === mcqOrigCorrectIdx) {
                                        newCorrectIndex = i;
                                    }
                                }

                                return {
                                    id: q.id,
                                    type: qtype,
                                    question: q.question,
                                    options: shuffledOptions,
                                    explanations: shuffledExplanations,
                                    correctAnswer: newCorrectIndex,
                                    originalCorrectIndex: q.correctAnswer !== undefined ? q.correctAnswer : (q.correctIndex || 0),
                                    shuffledToOriginal: shuffledToOriginal,
                                    audioData: shuffledAudioData,
                                    timestamp_seconds: q.timestamp_seconds != null ? q.timestamp_seconds : null
                                };
                            }

                            // For non-MCQ types, return the question data as-is.
                            // Defensively parse statements if returned as a JSON string from the server.
                            if (q.statements && typeof q.statements === 'string') {
                                try { q.statements = JSON.parse(q.statements); } catch (e) { q.statements = []; }
                            }
                            return q;
                        });

                        startStudentQuiz();
                    } else {
                        alert('No questions available. Please contact your teacher.');
                        location.reload();
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Load questions failed:', status, error);
                    alert('Failed to load questions. Please try again.');
                    location.reload();
                });
            }

            // ==========================================
            // QUIZ PLAYER (STUDENT + TEACHER PREVIEW)
            // ==========================================

            function startStudentQuiz() {
                currentQuestionIndex = 0;
                score = 0;
                scoredQuestionIndices = {};
                selectedAnswer = null;
                selectedLabel = null;

                // Restore saved question position when continuing an in-progress attempt.
                // localStorage stores currentQuestionIndex AFTER increment so it points to the next
                // unanswered question. The server stores the last-answered question number, which
                // would re-show an already-answered question if used directly. Prefer localStorage.
                if (currentAttemptId) {
                    var storageKey = 'va_progress_' + config.cmid + '_' + currentAttemptId;
                    var saved = localStorage.getItem(storageKey);
                    if (saved !== null) {
                        // FIX-VA-SCORE-RESTORE: Support both old format (integer) and new format ({q,s})
                        var parsedIdx, parsedScore;
                        try {
                            var savedData = JSON.parse(saved);
                            parsedIdx = savedData.q;
                            parsedScore = savedData.s || 0;
                        } catch (e) {
                            parsedIdx = parseInt(saved, 10);
                            parsedScore = 0;
                        }
                        if (!isNaN(parsedIdx) && parsedIdx > 0 && parsedIdx < quizData.length) {
                            currentQuestionIndex = parsedIdx;
                            score = parsedScore;
                        }
                    } else {
                        // Cross-device fallback: server stores last-answered question number.
                        // Add 1 to advance past the last answered question.
                        var serverQuestion = parseInt(config.inProgressAttemptQuestion || 0, 10);
                        if (!isNaN(serverQuestion) && serverQuestion > 0) {
                            var nextIdx = serverQuestion + 1;
                            if (nextIdx > 0 && nextIdx < quizData.length) {
                                currentQuestionIndex = nextIdx;
                            }
                        }
                    }
                }

                var startSection = document.getElementById('va-start-section');
                if (config.showVideoDuringQuiz && config.mediaType !== 'audio') {
                    // Keep video visible: hide watch-progress, action buttons, and card title only.
                    var watchProgress = document.getElementById('va-watch-progress');
                    if (watchProgress) watchProgress.style.display = 'none';
                    var videoActions = startSection ? startSection.querySelector('.va-video-actions') : null;
                    if (videoActions) videoActions.style.display = 'none';
                    var cardTitle = startSection ? startSection.querySelector('.va-card-title') : null;
                    if (cardTitle) cardTitle.style.display = 'none';
                    if (startSection) startSection.classList.add('va-video-during-quiz');
                } else {
                    if (startSection) startSection.style.display = 'none';
                }
                // Pause the video when the quiz starts.
                if (ytPlayer && ytPlayer.pauseVideo) {
                    ytPlayer.pauseVideo();
                }

                document.getElementById('va-quiz-player').style.display = 'block';

                // FIX-VA-STICKY-VIDEO-SCROLL (v1.0.88): Scroll the start section into view so
                // the compact video and the quiz card immediately below it are both on-screen.
                if (config.showVideoDuringQuiz && config.mediaType !== 'audio' && startSection) {
                    startSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                showQuestion();
            }

            function showQuestion() {
                var q = quizData[currentQuestionIndex];

                var counterEl = document.getElementById('va-question-counter');
                if (counterEl) counterEl.textContent = 'Question ' + (currentQuestionIndex + 1) + ' of ' + quizData.length;

                var scoreEl = document.getElementById('va-quiz-score');
                if (scoreEl) scoreEl.textContent = 'Score: ' + score + '/' + quizData.length;

                var questionTextEl = document.getElementById('va-question-text');
                if (questionTextEl) questionTextEl.textContent = q.question;

                // Chapter timestamp link  -  show clickable "Jump to X:XX" if enabled and question has a timestamp.
                var existingStamp = document.getElementById('va-chapter-stamp');
                if (existingStamp) existingStamp.parentNode && existingStamp.parentNode.removeChild(existingStamp);
                // FIX-VA-TIMESTAMP-DIAG (v1.0.109): mum reported timestamps stopped showing
                // up. None of the v1.0.108 changes touched timestamp render or storage paths,
                // so the cause is one of three things: (a) the activity-level
                // `showchapterstamps` toggle is off (config), (b) the questions were
                // generated/regenerated without a timestamp_seconds field (data), or
                // (c) the question is on an audio-mode activity (which intentionally
                // suppresses chapter stamps). Log all three signals for every question so
                // we can confirm in the browser console which leg is failing.
                // FIX-VA-TIMESTAMP-HYBRID-FALLBACK (v1.0.113): mum reported "timestamps still
                // not showing" even after the v1.0.111 prompt fix.  Root cause: legacy questions
                // saved before v1.0.111 have q.timestamp_seconds == null, AND transcripts that
                // were pasted without "MM:SS" time markers cause the AI to leave timestamp_seconds
                // null even on fresh generations.  Hybrid behaviour selected by mum: prefer the
                // AI-extracted timestamp when present (precise), otherwise fall back to evenly
                // distributing questions across the video duration so every question still shows
                // a clickable "Jump to" button.  Fallback buttons are visually marked with a
                // tilde ("~") prefix and a tooltip so students know the jump point is approximate.
                var realTs = (q && q.timestamp_seconds != null) ? parseInt(q.timestamp_seconds, 10) : null;
                if (realTs !== null && (isNaN(realTs) || realTs < 0)) realTs = null;
                var effectiveTs = realTs;
                var isFallbackTs = false;
                if (effectiveTs === null && config.showChapterStamps && config.mediaType !== 'audio') {
                    var totalQs = Array.isArray(quizData) ? quizData.length : 0;
                    var vidDur = (ytPlayer && ytPlayer.getDuration) ? ytPlayer.getDuration() : 0;
                    if (vidDur > 0 && totalQs > 0) {
                        // Place each question at the START of its evenly-sized segment so the
                        // student never jumps PAST the relevant content.
                        effectiveTs = Math.floor(currentQuestionIndex * (vidDur / totalQs));
                        isFallbackTs = true;
                    }
                }
                console.log('[VA-DIAG] chapter-stamp Q' + (currentQuestionIndex + 1) +
                    ' showChapterStamps=' + !!config.showChapterStamps +
                    ' mediaType=' + config.mediaType +
                    ' timestamp_seconds=' + (q && q.timestamp_seconds !== undefined ? q.timestamp_seconds : 'undefined') +
                    ' effective=' + effectiveTs + (isFallbackTs ? ' (fallback)' : (realTs !== null ? ' (real)' : '')) +
                    ' will_render=' + (!!config.showChapterStamps && effectiveTs !== null && config.mediaType !== 'audio'));
                if (config.showChapterStamps && effectiveTs !== null && config.mediaType !== 'audio') {
                    var stampSecs = effectiveTs;
                    if (!isNaN(stampSecs) && stampSecs >= 0) {
                        // FIX-VA-TIMESTAMP-OFFSET: Seek 1 second before the stored timestamp.
                        // AI-generated timestamps occasionally land 1 s after the content starts
                        // because transcripts mark when a word is uttered, not the moment the
                        // concept begins. Subtracting 1 s gives students a natural lead-in and
                        // prevents "Jump to X:XX" pointing 1 s past the relevant section.
                        // For fallback timestamps the offset doesn't matter (already approximate)
                        // but applying it consistently keeps behaviour uniform.
                        var seekSecs = Math.max(0, stampSecs - 1);
                        var stampMins = Math.floor(seekSecs / 60);
                        var stampRem = seekSecs % 60;
                        var stampTimeStr = stampMins + ':' + (stampRem < 10 ? '0' : '') + stampRem;
                        var stampEl = document.createElement('button');
                        stampEl.id = 'va-chapter-stamp';
                        stampEl.className = 'va-chapter-stamp-btn' + (isFallbackTs ? ' va-chapter-stamp-approx' : '');
                        stampEl.setAttribute('data-testid', 'button-chapter-stamp');
                        stampEl.setAttribute('type', 'button');
                        if (isFallbackTs) {
                            stampEl.setAttribute('title', 'Approximate location \u2014 the transcript did not contain time markers, so this jump point is estimated by spreading questions evenly across the video.');
                        }
                        var tildePrefix = isFallbackTs ? '~' : '';
                        stampEl.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Jump to ' + tildePrefix + stampTimeStr;
                        stampEl.addEventListener('click', function() {
                            if (ytPlayer && ytPlayer.seekTo) {
                                ytPlayer.seekTo(seekSecs, true);
                                if (ytPlayer.playVideo) ytPlayer.playVideo();
                            }
                            // If video is hidden (showVideoDuringQuiz off), briefly show start section.
                            if (!config.showVideoDuringQuiz) {
                                var ss = document.getElementById('va-start-section');
                                if (ss && ss.style.display === 'none') {
                                    ss.style.display = 'block';
                                    ss.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    setTimeout(function() {
                                        ss.style.display = 'none';
                                        var qp = document.getElementById('va-quiz-player');
                                        if (qp) qp.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    }, 8000);
                                }
                            }
                        });
                        var questionContainer = document.getElementById('va-question-container');
                        if (questionContainer && questionTextEl) {
                            questionContainer.insertBefore(stampEl, questionTextEl.nextSibling);
                        }
                    }
                }

                var feedbackContainer = document.getElementById('va-feedback-container');
                if (feedbackContainer) feedbackContainer.style.display = 'none';

                var tryAgainBtn = document.getElementById('va-try-again-btn');
                if (tryAgainBtn) tryAgainBtn.style.display = 'none';

                var nextBtn = document.getElementById('va-next-question-btn');
                if (nextBtn) nextBtn.style.display = 'none';

                selectedAnswer = null;
                selectedLabel = null;

                var type = q.type || 'mcq';
                var checkBtn = document.getElementById('va-check-answer-btn');

                switch (type) {
                    case 'cardselect':
                        renderCardSelect(q);
                        if (checkBtn) { checkBtn.style.display = 'block'; checkBtn.disabled = true; checkBtn.textContent = 'Check Answer'; }
                        break;
                    case 'matching':
                        renderMatching(q);
                        if (checkBtn) checkBtn.style.display = 'none';
                        break;
                    case 'ordering':
                        renderOrdering(q);
                        if (checkBtn) { checkBtn.style.display = 'block'; checkBtn.disabled = false; checkBtn.textContent = 'Check Order'; }
                        break;
                    case 'columnsort':
                        renderColumnSort(q);
                        if (checkBtn) checkBtn.style.display = 'none';
                        break;
                    case 'categorysort':
                        renderCategorySort(q);
                        if (checkBtn) checkBtn.style.display = 'none';
                        break;
                    case 'flashcards':
                        renderFlashcards(q);
                        if (checkBtn) checkBtn.style.display = 'none';
                        break;
                    case 'truefalseswipe':
                        renderTrueFalseSwipe(q);
                        if (checkBtn) checkBtn.style.display = 'none';
                        break;
                    case 'fillinblank':
                        renderFillInBlank(q);
                        if (checkBtn) checkBtn.style.display = 'none';
                        break;
                    default:
                        renderMCQ(q);
                        if (checkBtn) { checkBtn.style.display = 'block'; checkBtn.disabled = true; checkBtn.textContent = 'Check Answer'; }
                        break;
                }
            }

            function renderMCQ(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var letters = ['A', 'B', 'C', 'D'];
                var optionsHtml = '';
                q.options.forEach(function(option, index) {
                    optionsHtml += '<div class="va-option" data-index="' + index + '" data-label="' + letters[index] + '">';
                    optionsHtml += '<span class="va-option-letter">' + letters[index] + '</span>';
                    optionsHtml += '<span class="va-option-text">' + escapeHtml(option) + '</span>';
                    optionsHtml += '</div>';
                });
                optionsContainer.innerHTML = optionsHtml;

                selectedAnswer = null;
                selectedLabel = null;
                var checkBtn = document.getElementById('va-check-answer-btn');

                $('.va-option').on('click', function() {
                    if ($(this).hasClass('disabled')) return;
                    $('.va-option').removeClass('selected');
                    $(this).addClass('selected');
                    selectedAnswer = parseInt($(this).data('index'), 10);
                    selectedLabel = $(this).data('label') || null;
                    if (checkBtn) checkBtn.disabled = false;
                });
            }

            function renderCardSelect(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var cards = q.cards || [];
                var html = '<div class="va-cardselect-container">';
                html += '<div class="va-cardselect-instruction">Select the correct answer</div>';
                html += '<div class="va-cards-grid">';

                cards.forEach(function(card, idx) {
                    html += '<div class="va-card-option" data-index="' + idx + '">';
                    html += '<div class="va-card-icon">' + getVAIcon(card.icon || 'star') + '</div>';
                    html += '<div class="va-card-label">' + escapeHtml(card.label) + '</div>';
                    html += '<div class="va-card-desc">' + escapeHtml(card.description) + '</div>';
                    html += '</div>';
                });

                html += '</div></div>';
                optionsContainer.innerHTML = html;

                var checkBtn = document.getElementById('va-check-answer-btn');

                $('.va-card-option').on('click', function() {
                    if ($(this).hasClass('va-disabled')) return;
                    $('.va-card-option').removeClass('va-card-selected');
                    $(this).addClass('va-card-selected');
                    selectedAnswer = parseInt($(this).data('index'), 10);
                    if (checkBtn) checkBtn.disabled = false;
                });

                // FIX-VA-CARDSELECT-PREWARM (v1.0.131): fire background Chirp requests for
                // any missing per-card audio slots the moment the question is displayed.
                // Students typically take a few seconds to read before clicking — we use
                // that reading time so audio is ready (or nearly ready) when they do click,
                // eliminating the ~5 s live-API delay from topUpMissingClipAndPlay().
                prewarmCardAudio(q);
            }

            // FIX-VA-CARDSELECT-PREWARM (v1.0.131): pre-fetches missing per-card audio clips
            // in the background as soon as a card-select question is displayed. Chirp API
            // calls are fired for every card that has no cached audio, using the same
            // explanation-text logic as checkCardSelectAnswer(). Results are cached directly
            // into q.audioData[] so checkCardSelectAnswer() finds them instantly and skips
            // the on-demand top-up. We never call playVoiceover() here — this is cache-only.
            // Guard: only runs when config.voiceLanguage is set (voiceover configured for
            // this activity). Does not overwrite slots already populated.
            function prewarmCardAudio(q) {
                if (!config.voiceLanguage) return;
                var cards = q.cards || [];
                if (!cards.length) return;

                var voiceLanguage = config.voiceLanguage || 'en-AU';
                var voiceId = config.voiceStyle || 'Aoede';
                var correctIdx = q.correctIndex !== undefined ? parseInt(q.correctIndex, 10) : 0;
                var hasPerCardText = Array.isArray(q.explanations) && q.explanations.length === cards.length;
                var hasPerCardAudio = Array.isArray(q.audioData) && q.audioData.length === cards.length && q.audioData.length > 1;

                // Nothing missing — skip entirely.
                if (hasPerCardAudio && q.audioData.every(function(a) { return !!a; })) {
                    console.log('[VA-DIAG] prewarm skipped — all card audio slots present');
                    return;
                }

                cards.forEach(function(card, cardIdx) {
                    // Skip already-cached slots.
                    if (hasPerCardAudio && q.audioData[cardIdx]) return;

                    // Build the explanation text — mirrors checkCardSelectAnswer() exactly.
                    var isCorrectCard = (cardIdx === correctIdx);
                    var text;
                    if (hasPerCardText) {
                        text = (q.explanations[cardIdx] || q.explanation || '').trim();
                    } else if (isCorrectCard) {
                        text = (q.explanation || '').trim();
                    } else {
                        var lbl = (card && card.label) ? String(card.label).trim() : 'That option';
                        text = 'Incorrect. ' + lbl + " isn't quite the right fit here. Have another look at the question and try a different option.";
                    }
                    if (!text) return;

                    // Ensure correct/incorrect prefix — mirrors ensureVoiceoverPrefix().
                    if (!/^(Correct|Incorrect)/i.test(text)) {
                        text = (isCorrectCard ? 'Correct. ' : 'Incorrect. ') + text;
                    }

                    console.log('[VA-DIAG] prewarm card ' + cardIdx + ' qid=' + (q.id || '?') + ' textLen=' + text.length);
                    ajaxCall('ttssingle', {
                        text: text,
                        voiceLanguage: voiceLanguage,
                        voiceId: voiceId
                    }, 30000).done(function(response) {
                        if (response && response.ok && response.audioData) {
                            if (!Array.isArray(q.audioData)) q.audioData = [];
                            while (q.audioData.length < cards.length) q.audioData.push('');
                            if (!q.audioData[cardIdx]) {
                                q.audioData[cardIdx] = response.audioData;
                                console.log('[VA-DIAG] prewarm card ' + cardIdx + ' cached len=' + response.audioData.length);
                            }
                        } else {
                            console.log('[VA-DIAG] prewarm card ' + cardIdx + ' TTS failed:', (response && response.error) || 'no audioData');
                        }
                    }).fail(function(xhr, status, error) {
                        console.log('[VA-DIAG] prewarm card ' + cardIdx + ' request failed:', status, error);
                    });
                });
            }

            function renderMatching(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var pairs = q.pairs || [];
                if (!pairs || pairs.length === 0) {
                    optionsContainer.innerHTML = '<p>No matching pairs available.</p>';
                    return;
                }

                var rightOptions = shuffleArray(pairs.map(function(p, i) {
                    return { text: p.right, pairIndex: i };
                }));

                // userPairings: leftIdx  ->  pairIndex of the right option chosen
                var userPairings = {};
                var selectedLeft = null; // leftIdx currently selected (null = none)
                var isSubmitted = false;

                // Colour palette for paired items (cycles if more pairs than colours)
                var pairColors = ['#667eea','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#ec4899','#14b8a6'];

                var checkSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
                var crossSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>';

                function renderUI() {
                    var pairedCount = Object.keys(userPairings).length;
                    var allPaired = pairedCount === pairs.length;

                    var html = '<div class="va-matching-container">';
                    // FIX-VA-MATCH-UX: Clearer step-by-step instruction with visual step indicator.
                    if (selectedLeft === null) {
                        html += '<div class="va-matching-steps">';
                        html += '<div class="va-match-step va-match-step-active"><span class="va-match-step-num">1</span><span class="va-match-step-text">Tap a <strong>term</strong> on the left</span></div>';
                        html += '<div class="va-match-step-arrow">&#8594;</div>';
                        html += '<div class="va-match-step"><span class="va-match-step-num">2</span><span class="va-match-step-text">Tap its <strong>match</strong> on the right</span></div>';
                        html += '</div>';
                    } else {
                        html += '<div class="va-matching-steps">';
                        html += '<div class="va-match-step"><span class="va-match-step-num va-match-step-done">&#10003;</span><span class="va-match-step-text">Term selected</span></div>';
                        html += '<div class="va-match-step-arrow va-match-step-arrow-active">&#8594;</div>';
                        html += '<div class="va-match-step va-match-step-active"><span class="va-match-step-num">2</span><span class="va-match-step-text">Now tap its <strong>match</strong> on the right</span></div>';
                        html += '</div>';
                    }
                    html += '<div class="va-matching-cols">';

                    // -- LEFT COLUMN --------------------------------------
                    html += '<div class="va-matching-col">';
                    html += '<div class="va-matching-col-header"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg> Terms <span style="font-size:0.75em;font-weight:400;opacity:0.7;">(tap to select)</span></div>';
                    pairs.forEach(function(pair, leftIdx) {
                        var pairedPairIdx = userPairings[leftIdx];
                        var isPaired = pairedPairIdx !== undefined;
                        var isSelected = selectedLeft === leftIdx && !isPaired;
                        var color = isPaired ? pairColors[leftIdx % pairColors.length] : null;

                        var cls = 'va-match-item va-match-left-card';
                        var extra = '';
                        if (isSubmitted && isPaired) {
                            cls += (pairedPairIdx === leftIdx) ? ' va-match-matched' : ' va-match-wrong';
                        } else if (isPaired) {
                            extra = 'style="border-color:' + color + ';background:' + color + '1a;"';
                        } else if (isSelected) {
                            cls += ' va-match-selected';
                        }

                        html += '<div class="' + cls + '" data-left-idx="' + leftIdx + '" ' + extra + '>';
                        if (isSubmitted && isPaired) {
                            html += (pairedPairIdx === leftIdx) ? checkSvg : crossSvg;
                        } else if (isPaired) {
                            html += '<span class="va-match-pair-badge" style="background:' + color + '">' + (leftIdx + 1) + '</span>';
                        } else {
                            html += '<span class="va-match-num-badge">' + (leftIdx + 1) + '</span>';
                        }
                        html += '<span class="va-match-card-text">' + escapeHtml(pair.left) + '</span>';
                        html += '</div>';
                    });
                    html += '</div>';

                    // -- RIGHT COLUMN -------------------------------------
                    html += '<div class="va-matching-col">';
                    html += '<div class="va-matching-col-header"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Definitions <span style="font-size:0.75em;font-weight:400;opacity:0.7;">(tap to match)</span></div>';
                    rightOptions.forEach(function(opt, i) {
                        var pairedToLeft = null;
                        Object.keys(userPairings).forEach(function(lk) {
                            if (userPairings[parseInt(lk, 10)] === opt.pairIndex) pairedToLeft = parseInt(lk, 10);
                        });
                        var isPaired = pairedToLeft !== null;
                        var isAvailable = !isPaired && selectedLeft !== null && !isSubmitted;
                        var color = isPaired ? pairColors[pairedToLeft % pairColors.length] : null;

                        var cls = 'va-match-item va-match-right-card';
                        var extra = '';
                        if (isSubmitted && isPaired) {
                            cls += (pairedToLeft === opt.pairIndex) ? ' va-match-matched' : ' va-match-wrong';
                        } else if (isPaired) {
                            extra = 'style="border-color:' + color + ';background:' + color + '1a;"';
                        } else if (isAvailable) {
                            cls += ' va-match-available';
                        }

                        html += '<div class="' + cls + '" data-right-idx="' + i + '" data-pair-index="' + opt.pairIndex + '" ' + extra + '>';
                        if (isPaired && !isSubmitted) {
                            html += '<span class="va-match-pair-badge" style="background:' + color + '">' + (pairedToLeft + 1) + '</span>';
                        }
                        if (isSubmitted && isPaired) {
                            html += (pairedToLeft === opt.pairIndex) ? checkSvg : crossSvg;
                        }
                        html += '<span class="va-match-card-text">' + escapeHtml(opt.text) + '</span>';
                        html += '</div>';
                    });
                    html += '</div>';

                    html += '</div>'; // va-matching-cols

                    // Progress hint
                    if (!isSubmitted && pairedCount > 0 && pairedCount < pairs.length) {
                        html += '<div class="va-matching-progress">' + pairedCount + ' of ' + pairs.length + ' matched</div>';
                    }

                    // Check button (only appears when all paired and not yet submitted)
                    if (!isSubmitted && allPaired) {
                        html += '<button class="va-btn va-btn-primary va-match-submit-btn" id="va-match-submit">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' +
                            ' Check Matches</button>';
                    }

                    html += '</div>'; // va-matching-container
                    optionsContainer.innerHTML = html;

                    if (isSubmitted) return;

                    // -- LEFT CLICK HANDLER -------------------------------
                    document.querySelectorAll('.va-match-left-card').forEach(function(el) {
                        el.addEventListener('click', function() {
                            var leftIdx = parseInt(el.getAttribute('data-left-idx'), 10);
                            if (userPairings[leftIdx] !== undefined) {
                                // Tap a paired card to un-pair it and re-select
                                delete userPairings[leftIdx];
                                selectedLeft = leftIdx;
                            } else {
                                selectedLeft = (selectedLeft === leftIdx) ? null : leftIdx;
                            }
                            renderUI();
                        });
                    });

                    // -- RIGHT CLICK HANDLER ------------------------------
                    document.querySelectorAll('.va-match-right-card').forEach(function(el) {
                        el.addEventListener('click', function() {
                            if (selectedLeft === null) return;
                            var pairIndex = parseInt(el.getAttribute('data-pair-index'), 10);
                            // Un-pair any left already linked to this right option
                            Object.keys(userPairings).forEach(function(lk) {
                                if (userPairings[parseInt(lk, 10)] === pairIndex) delete userPairings[parseInt(lk, 10)];
                            });
                            userPairings[selectedLeft] = pairIndex;
                            selectedLeft = null;
                            renderUI();
                        });
                    });

                    // -- CHECK BUTTON -------------------------------------
                    var submitBtn = document.getElementById('va-match-submit');
                    if (submitBtn) {
                        submitBtn.addEventListener('click', function() {
                            isSubmitted = true;
                            // BUG-VA-MATCH-FALSE-POSITIVE: Old dropdown matching (v1.0.59 and earlier)
                            // graded allCorrect=true if every dropdown was filled, regardless of whether
                            // the selections were correct  -  producing false "All matched correctly!" even
                            // with wrong answers. The click-card UI stores opt.pairIndex (the original
                            // pair-array index) in userPairings[leftIdx]. Each pairs[i].right has
                            // pairIndex===i, so the correct answer for left item i is always pairIndex===i.
                            // Build an explicit correctPairings map to make this invariant self-documenting
                            // and guard against future regressions.
                            var correctPairings = {};
                            pairs.forEach(function(_, i) { correctPairings[i] = i; });
                            var allCorrect = true;
                            pairs.forEach(function(_, leftIdx) {
                                if (userPairings[leftIdx] !== correctPairings[leftIdx]) allCorrect = false;
                            });
                            renderUI();
                            if (allCorrect) {
                                tryScoreCurrentQuestion();
                                playCorrectSound();
                                if (q.id) saveAnswerToDatabase(q.id, 0, true);
                                showMatchingFeedback(q, true);
                            } else {
                                playIncorrectSound();
                                var matchFeedback = document.getElementById('va-feedback-container');
                                if (matchFeedback) {
                                    matchFeedback.innerHTML = '<div class="va-feedback va-feedback-incorrect"><strong>Some pairs are incorrect.</strong><p>Incorrect pairs are highlighted in red  -  re-pair them to try again.</p></div>';
                                    matchFeedback.style.display = 'block';
                                }
                                // FIX-VA-SCORING-MODE: In first-attempt mode, show Next instead of resetting pairs.
                                if (config.scoringMode === 1) {
                                    var matchNextBtn = document.getElementById('va-next-question-btn');
                                    if (matchNextBtn) {
                                        matchNextBtn.style.display = 'block';
                                        matchNextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                                    }
                                    if (submitBtn) submitBtn.style.display = 'none';
                                } else {
                                    setTimeout(function() {
                                        isSubmitted = false;
                                        pairs.forEach(function(_, leftIdx) {
                                            if (userPairings[leftIdx] !== correctPairings[leftIdx]) delete userPairings[leftIdx];
                                        });
                                        if (matchFeedback) { matchFeedback.style.display = 'none'; matchFeedback.innerHTML = ''; }
                                        renderUI();
                                    }, 1800);
                                }
                            }
                        });
                    }
                }

                renderUI();
            }

            function showMatchingFeedback(q, isCorrect) {
                var feedbackContainer = document.getElementById('va-feedback-container');
                if (feedbackContainer) {
                    // BUG-VA-MATCH-FEEDBACK-PARAM: Previously ignored the isCorrect parameter and
                    // always showed the success message regardless of actual grading result.
                    // Now uses the parameter to render the appropriate feedback class and title.
                    var fbClass = isCorrect ? 'va-feedback-correct' : 'va-feedback-incorrect';
                    var fbTitle = isCorrect ? 'All matched correctly!' : 'Some pairs are incorrect.';
                    feedbackContainer.innerHTML = '<div class="va-feedback ' + fbClass + '"><strong>' + fbTitle + '</strong>' +
                        (q.explanation ? '<p>' + escapeHtml(q.explanation) + '</p>' : '') + '</div>';
                    feedbackContainer.style.display = 'block';
                }
                // FIX-VA-VOICEOVER-DIAG (v1.0.103)
                console.log('[VA-DIAG] guard idx=0 q.type=' + (q && q.type) +
                    ' hasAD=' + !!(q && q.audioData) +
                    ' isArr=' + Array.isArray(q && q.audioData) +
                    ' adLen=' + ((q && q.audioData) ? q.audioData.length : 0) +
                    ' [0]=' + ((q && q.audioData && q.audioData[0]) ? ('set(' + q.audioData[0].length + ')') : 'EMPTY'));
                if (q.audioData && q.audioData[0]) {
                    playVoiceover(q.audioData[0]);
                }
                var nextBtn = document.getElementById('va-next-question-btn');
                if (nextBtn) {
                    nextBtn.style.display = 'block';
                    nextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                }
                var scoreEl = document.getElementById('va-quiz-score');
                if (scoreEl) scoreEl.textContent = 'Score: ' + score + '/' + quizData.length;
            }

            function renderOrdering(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var correctOrder = q.items.slice();
                var currentOrder = shuffleArray(correctOrder.slice());
                while (arraysEqual(currentOrder, correctOrder) && correctOrder.length > 1) {
                    currentOrder = shuffleArray(correctOrder.slice());
                }
                var selectedOrderIdx = null;

                function renderOrderState() {
                    var html = '<div class="va-ordering-container">';
                    html += '<div class="va-ordering-instruction">Tap two items to swap their positions, then check your order</div>';
                    html += '<div class="va-ordering-list">';
                    currentOrder.forEach(function(item, idx) {
                        var isSelected = selectedOrderIdx === idx;
                        html += '<div class="va-order-item' + (isSelected ? ' va-order-selected' : '') + '" data-idx="' + idx + '">';
                        html += '<span class="va-order-num">' + (idx + 1) + '</span>';
                        html += '<span class="va-order-handle"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/></svg></span>';
                        html += '<span class="va-order-text">' + escapeHtml(item) + '</span>';
                        html += '</div>';
                    });
                    html += '</div></div>';
                    optionsContainer.innerHTML = html;

                    $('.va-order-item').on('click', function() {
                        var idx = parseInt($(this).data('idx'), 10);
                        if (selectedOrderIdx === null) {
                            selectedOrderIdx = idx;
                            $(this).addClass('va-order-selected');
                        } else if (selectedOrderIdx === idx) {
                            selectedOrderIdx = null;
                            $(this).removeClass('va-order-selected');
                        } else {
                            var temp = currentOrder[selectedOrderIdx];
                            currentOrder[selectedOrderIdx] = currentOrder[idx];
                            currentOrder[idx] = temp;
                            selectedOrderIdx = null;
                            renderOrderState();
                        }
                    });
                }

                renderOrderState();

                quizData[currentQuestionIndex]._currentOrder = currentOrder;
                quizData[currentQuestionIndex]._correctOrder = correctOrder;
                quizData[currentQuestionIndex]._renderOrderState = renderOrderState;
            }

            function renderColumnSort(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var items = shuffleArray(q.items.slice());
                var currentItemIdx = 0;
                var correctCount = 0;
                var sortedA = [];
                var sortedB = [];

                function renderColState() {
                    var html = '<div class="va-columnsort-container">';
                    html += '<div class="va-columnsort-progress"><div class="va-columnsort-progress-bar"><div class="va-columnsort-progress-fill" style="width:' + (correctCount / items.length * 100) + '%"></div></div>';
                    html += '<span>' + correctCount + ' / ' + items.length + '</span></div>';

                    if (currentItemIdx < items.length) {
                        html += '<div class="va-current-sort-item">' + escapeHtml(items[currentItemIdx].text) + '</div>';
                    }

                    html += '<div class="va-columns-layout">';
                    html += '<div class="va-column va-column-a" data-col="A">';
                    html += '<div class="va-column-header va-column-header-a"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' + escapeHtml(q.columnA) + '</div>';
                    html += '<div class="va-column-items">';
                    sortedA.forEach(function(item) {
                        html += '<div class="va-locked-item">' + escapeHtml(item.text) + '</div>';
                    });
                    html += '</div></div>';

                    html += '<div class="va-column va-column-b" data-col="B">';
                    html += '<div class="va-column-header va-column-header-b"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' + escapeHtml(q.columnB) + '</div>';
                    html += '<div class="va-column-items">';
                    sortedB.forEach(function(item) {
                        html += '<div class="va-locked-item">' + escapeHtml(item.text) + '</div>';
                    });
                    html += '</div></div>';
                    html += '</div></div>';

                    optionsContainer.innerHTML = html;

                    if (currentItemIdx < items.length) {
                        $('.va-column').on('click', function() {
                            var chosenCol = $(this).data('col');
                            var item = items[currentItemIdx];
                            var correct = item.column === chosenCol;

                            if (correct) {
                                playCorrectSound();
                                if (chosenCol === 'A') sortedA.push(item);
                                else sortedB.push(item);
                                correctCount++;
                                currentItemIdx++;

                                if (currentItemIdx >= items.length) {
                                    tryScoreCurrentQuestion();
                                    if (q.id) saveAnswerToDatabase(q.id, 0, true);
                                    renderColState();
                                    showInteractiveFeedback(q, true);
                                } else {
                                    renderColState();
                                }
                            } else {
                                playIncorrectSound();
                                var sortItem = $('.va-current-sort-item');
                                sortItem.addClass('va-shake');
                                setTimeout(function() { sortItem.removeClass('va-shake'); }, 500);
                            }
                        });
                    }
                }

                renderColState();
            }

            function renderCategorySort(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var unsorted = shuffleArray(q.items.slice());
                var sorted = {};
                q.categories.forEach(function(_, i) { sorted[i] = []; });
                var currentItemIdx = 0;

                function renderCatState() {
                    var html = '<div class="va-categorysort-container">';
                    html += '<div class="va-columnsort-progress"><div class="va-columnsort-progress-bar"><div class="va-columnsort-progress-fill" style="width:' + (currentItemIdx / unsorted.length * 100) + '%"></div></div>';
                    html += '<span>' + currentItemIdx + ' / ' + unsorted.length + '</span></div>';

                    if (currentItemIdx < unsorted.length) {
                        html += '<div class="va-current-sort-item">' + escapeHtml(unsorted[currentItemIdx].text) + '</div>';
                    }

                    html += '<div class="va-categories-grid">';
                    q.categories.forEach(function(catName, catIdx) {
                        html += '<div class="va-category-bucket" data-cat="' + catIdx + '">';
                        html += '<div class="va-category-header">' + escapeHtml(catName) + '</div>';
                        html += '<div class="va-category-items">';
                        (sorted[catIdx] || []).forEach(function(item) {
                            html += '<div class="va-locked-item">' + escapeHtml(item.text) + '</div>';
                        });
                        html += '</div></div>';
                    });
                    html += '</div></div>';

                    optionsContainer.innerHTML = html;

                    if (currentItemIdx < unsorted.length) {
                        $('.va-category-bucket').on('click', function() {
                            var chosenCat = parseInt($(this).data('cat'), 10);
                            var item = unsorted[currentItemIdx];
                            // FIX-VA-CATSORT-NORMALIZE: Handle numeric index, string name, or
                            // string-of-number (e.g. "0") that AI may generate due to example
                            // mismatch in prompt. All three forms must map to the correct bucket.
                            var correct = item.category === chosenCat ||
                                (typeof item.category === 'string' && parseInt(item.category, 10) === chosenCat) ||
                                item.category === q.categories[chosenCat];

                            if (correct) {
                                playCorrectSound();
                                sorted[chosenCat].push(item);
                                currentItemIdx++;

                                if (currentItemIdx >= unsorted.length) {
                                    tryScoreCurrentQuestion();
                                    if (q.id) saveAnswerToDatabase(q.id, 0, true);
                                    renderCatState();
                                    showInteractiveFeedback(q, true);
                                } else {
                                    renderCatState();
                                }
                            } else {
                                playIncorrectSound();
                                var sortItem = $('.va-current-sort-item');
                                sortItem.addClass('va-shake');
                                setTimeout(function() { sortItem.removeClass('va-shake'); }, 500);
                            }
                        });
                    }
                }

                renderCatState();
            }

            function renderFlashcards(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var cards = q.cards || [];
                var currentCardIdx = 0;
                var flipped = false;
                // FIX-VA-FLASHCARD-SCORE: Track self-assessment per card.
                // score++ only fires when the student marks ALL cards as "Got it!".
                // FIX-VA-FLASHCARD-DOUBLE: finished flag prevents score++ firing twice
                // if the student clicks Got it / Still learning after the last card.
                var allKnown = true;
                var finished = false;

                function advanceCard(knew) {
                    if (finished) return;
                    if (!knew) allKnown = false;
                    if (currentCardIdx < cards.length - 1) {
                        currentCardIdx++;
                        flipped = false;
                        renderFCState();
                    } else {
                        finished = true;
                        var didScore = allKnown;
                        if (didScore) tryScoreCurrentQuestion();
                        if (q.id) saveAnswerToDatabase(q.id, 0, didScore);
                        // FIX-VA-FLASHCARD-SOUND: Play correct/incorrect tone on flashcard completion
                        if (didScore) { playCorrectSound(); } else { playIncorrectSound(); }
                        // v1.0.59 FIX-FLASHCARD-FEEDBACK: Keep last card visible (answer side).
                        //   Show feedback below the card, then "Next Activity"/"Finish Quiz"
                        //   button below the feedback  -  all inline in the options container.
                        //   No separate blank completion slide. Files: videoactivity.js, styles.css.
                        var optCont = document.getElementById('va-options-container');
                        if (optCont) {
                            var lastCard = cards[cards.length - 1];
                            var isLastQ = (currentQuestionIndex >= quizData.length - 1);
                            var fbClass = didScore ? 'va-feedback-correct' : 'va-feedback-incorrect';
                            var fbTitle = didScore ? 'Well done!' : 'Some answers were incorrect.';
                            var fcHtml = '<div class="va-flashcards-container">';
                            fcHtml += '<div class="va-columnsort-progress"><div class="va-columnsort-progress-bar"><div class="va-columnsort-progress-fill" style="width:100%"></div></div>';
                            fcHtml += '<span>' + cards.length + ' / ' + cards.length + '</span></div>';
                            fcHtml += '<div class="va-flashcard-wrapper">';
                            fcHtml += '<div class="va-flashcard va-flashcard-flipped">';
                            fcHtml += '<div class="va-flashcard-face va-flashcard-front">';
                            fcHtml += '<div class="va-flashcard-number">' + cards.length + '</div>';
                            fcHtml += '<div class="va-flashcard-label">Question</div>';
                            fcHtml += '<div class="va-flashcard-text">' + escapeHtml(lastCard.front) + '</div>';
                            fcHtml += '</div>';
                            fcHtml += '<div class="va-flashcard-face va-flashcard-back">';
                            fcHtml += '<div class="va-flashcard-back-icon">';
                            fcHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
                            fcHtml += '</div>';
                            fcHtml += '<div class="va-flashcard-label">Answer</div>';
                            fcHtml += '<div class="va-flashcard-text">' + escapeHtml(lastCard.back) + '</div>';
                            fcHtml += '</div>';
                            fcHtml += '</div>';
                            fcHtml += '</div>';
                            fcHtml += '<div class="va-flashcard-completion">';
                            fcHtml += '<div class="va-feedback ' + fbClass + '"><strong>' + fbTitle + '</strong>';
                            if (q.explanation) { fcHtml += '<p>' + escapeHtml(q.explanation) + '</p>'; }
                            fcHtml += '</div>';
                            fcHtml += '<div class="va-flashcard-completion-btn">';
                            fcHtml += '<button class="va-btn va-btn-primary" id="va-flashcard-nav-btn">';
                            fcHtml += isLastQ ? 'Finish Quiz' : 'Next Activity';
                            fcHtml += '</button>';
                            fcHtml += '</div>';
                            fcHtml += '</div>';
                            fcHtml += '</div>';
                            optCont.innerHTML = fcHtml;
                            var navBtn = document.getElementById('va-flashcard-nav-btn');
                            if (navBtn) {
                                navBtn.addEventListener('click', function() { nextQuestion(); });
                            }
                        }
                        var scoreEl = document.getElementById('va-quiz-score');
                        if (scoreEl) scoreEl.textContent = 'Score: ' + score + '/' + quizData.length;
                        // FIX-VA-VOICEOVER-DIAG (v1.0.103)
                        console.log('[VA-DIAG] guard(fc) idx=0 q.type=' + (q && q.type) +
                            ' hasAD=' + !!(q && q.audioData) +
                            ' adLen=' + ((q && q.audioData) ? q.audioData.length : 0) +
                            ' [0]=' + ((q && q.audioData && q.audioData[0]) ? ('set(' + q.audioData[0].length + ')') : 'EMPTY'));
                        if (q.audioData && q.audioData[0]) { playVoiceover(q.audioData[0]); }
                    }
                }

                function renderFCState() {
                    var html = '<div class="va-flashcards-container">';
                    html += '<div class="va-columnsort-progress"><div class="va-columnsort-progress-bar"><div class="va-columnsort-progress-fill" style="width:' + ((currentCardIdx) / cards.length * 100) + '%"></div></div>';
                    html += '<span>' + (currentCardIdx + 1) + ' / ' + cards.length + '</span></div>';

                    var card = cards[currentCardIdx];
                    html += '<div class="va-flashcard-wrapper">';
                    html += '<div class="va-flashcard' + (flipped ? ' va-flashcard-flipped' : '') + '" id="va-flashcard">';
                    html += '<div class="va-flashcard-face va-flashcard-front">';
                    html += '<div class="va-flashcard-number">' + (currentCardIdx + 1) + '</div>';
                    html += '<div class="va-flashcard-label">Question</div>';
                    html += '<div class="va-flashcard-text">' + escapeHtml(card.front) + '</div>';
                    html += '<div class="va-flashcard-hint">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 15l-2 5L9 9l11 4-5 2z"/><path d="M14.25 14.25L18 10"/></svg> ';
                    html += 'Tap to reveal answer';
                    html += '</div>';
                    html += '</div>';
                    html += '<div class="va-flashcard-face va-flashcard-back">';
                    html += '<div class="va-flashcard-back-icon">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
                    html += '</div>';
                    html += '<div class="va-flashcard-label">Answer</div>';
                    html += '<div class="va-flashcard-text">' + escapeHtml(card.back) + '</div>';
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';

                    if (flipped) {
                        // v1.0.56 FIX-FLIPCARD-BTN: Replace "Got it!" / "Still learning" with a single
                        // progression button  -  "Next Card" for all but the final card, "Next" on the last.
                        var isLastCard = (currentCardIdx === cards.length - 1);
                        html += '<div class="va-flashcard-actions va-flashcard-self-assess">';
                        html += '<button class="va-btn va-btn-primary" id="va-flashcard-got-it">';
                        html += isLastCard ? 'Next' : 'Next Card';
                        html += '</button>';
                        html += '</div>';
                    }

                    html += '</div>';
                    optionsContainer.innerHTML = html;

                    var flashcardEl = document.getElementById('va-flashcard');
                    if (flashcardEl && !flipped) {
                        flashcardEl.addEventListener('click', function() {
                            flipped = true;
                            this.classList.add('va-flashcard-flipped');
                            setTimeout(function() { renderFCState(); }, 400);
                        });
                    }

                    var gotItBtn = document.getElementById('va-flashcard-got-it');
                    if (gotItBtn) {
                        // v1.0.56 FIX-FLIPCARD-BTN: Single "Next Card"/"Next" button always advances
                        gotItBtn.addEventListener('click', function() { advanceCard(true); });
                    }
                }

                var checkBtn = document.getElementById('va-check-answer-btn');
                if (checkBtn) checkBtn.style.display = 'none';
                renderFCState();
            }

            function renderTrueFalseSwipe(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var statements = q.statements || [];
                var currentStmtIdx = 0;
                var correctCount = 0;
                var showingExplanation = false;
                var lastAnswerCorrect = null;

                function renderTFState() {
                    var html = '<div class="va-truefalse-container">';
                    html += '<div class="va-columnsort-progress"><div class="va-columnsort-progress-bar"><div class="va-columnsort-progress-fill" style="width:' + (currentStmtIdx / statements.length * 100) + '%"></div></div>';
                    html += '<span>' + currentStmtIdx + ' / ' + statements.length + '</span></div>';

                    if (currentStmtIdx < statements.length) {
                        var stmt = statements[currentStmtIdx];
                        html += '<div class="va-tfs-card-wrapper">';
                        html += '<div class="va-tfs-card" id="va-tfs-card">';
                        html += '<div class="va-tfs-statement">' + escapeHtml(stmt.text) + '</div>';

                        if (showingExplanation) {
                            html += '<div class="va-tfs-explanation">';
                            if (lastAnswerCorrect) {
                                html += '<div class="va-tfs-result va-tfs-result-correct">';
                                html += '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
                                html += ' Correct!';
                                html += '</div>';
                            } else {
                                html += '<div class="va-tfs-result va-tfs-result-incorrect">';
                                html += '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
                                html += ' Incorrect';
                                html += '</div>';
                            }
                            html += '<div class="va-tfs-badge-row">';
                            html += '<span class="va-tfs-correct-answer-label">Correct answer: </span>';
                            html += stmt.correct
                                ? '<span class="va-tfs-true-badge">True</span>'
                                : '<span class="va-tfs-false-badge">False</span>';
                            html += '</div>';
                            html += '<p>' + escapeHtml(stmt.explanation) + '</p>';
                            html += '</div>';
                        }

                        html += '</div>';
                        html += '</div>';

                        if (!showingExplanation) {
                            html += '<div class="va-tfs-buttons">';
                            html += '<button class="va-btn va-tfs-btn-false" id="va-tfs-false">';
                            html += '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
                            html += ' False';
                            html += '</button>';
                            html += '<button class="va-btn va-tfs-btn-true" id="va-tfs-true">';
                            html += '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
                            html += ' True';
                            html += '</button>';
                            html += '</div>';
                        } else {
                            html += '<div class="va-tfs-buttons">';
                            html += '<button class="va-btn va-btn-primary" id="va-tfs-continue">';
                            html += (currentStmtIdx < statements.length - 1) ? 'Next' : 'Done';
                            html += '</button>';
                            html += '</div>';
                        }
                    }

                    html += '</div>';
                    optionsContainer.innerHTML = html;

                    var falseBtn = document.getElementById('va-tfs-false');
                    if (falseBtn) {
                        falseBtn.addEventListener('click', function() { handleTFAnswer(false); });
                    }
                    var trueBtn = document.getElementById('va-tfs-true');
                    if (trueBtn) {
                        trueBtn.addEventListener('click', function() { handleTFAnswer(true); });
                    }

                    var continueBtn = document.getElementById('va-tfs-continue');
                    if (continueBtn) {
                        continueBtn.addEventListener('click', function() {
                            currentStmtIdx++;
                            showingExplanation = false;
                            if (currentStmtIdx >= statements.length) {
                                var allCorrect = correctCount === statements.length;
                                if (allCorrect) tryScoreCurrentQuestion();
                                if (q.id) saveAnswerToDatabase(q.id, 0, allCorrect);
                                showInteractiveFeedback(q, allCorrect);
                            } else {
                                renderTFState();
                            }
                        });
                    }

                    function handleTFAnswer(chosenTrue) {
                        var stmt = statements[currentStmtIdx];
                        var correct = chosenTrue === stmt.correct;
                        lastAnswerCorrect = correct;
                        if (correct) {
                            playCorrectSound();
                            correctCount++;
                        } else {
                            playIncorrectSound();
                            var card = document.getElementById('va-tfs-card');
                            if (card) card.classList.add('va-shake');
                        }
                        showingExplanation = true;
                        renderTFState();
                    }
                }

                var checkBtn = document.getElementById('va-check-answer-btn');
                if (checkBtn) checkBtn.style.display = 'none';
                renderTFState();
            }

            function renderFillInBlank(q) {
                var optionsContainer = document.getElementById('va-options-container');
                var blanks = q.blanks || [];
                var allWords = shuffleArray(blanks.map(function(b) { return b.answer; }).concat(q.distractors || []));
                var filledBlanks = {};
                var selectedBlankPos = null;

                function renderFIBState() {
                    var html = '<div class="va-fillinblank-container">';
                    html += '<div class="va-fib-text">';
                    var text = q.text || '';
                    for (var i = blanks.length; i >= 1; i--) {
                        var placeholder = '___' + i + '___';
                        var pos = i - 1;
                        var filled = filledBlanks[pos];
                        var blankHtml;
                        if (filled) {
                            var isCorrect = filled === blanks[pos].answer;
                            blankHtml = '<span class="va-fib-blank va-fib-filled' +
                                (filledBlanks[pos + '_checked'] ? (isCorrect ? ' va-fib-correct' : ' va-fib-incorrect') : '') +
                                (selectedBlankPos === pos ? ' va-fib-selected' : '') +
                                '" data-pos="' + pos + '">' + escapeHtml(filled) + '</span>';
                        } else {
                            blankHtml = '<span class="va-fib-blank va-fib-empty' +
                                (selectedBlankPos === pos ? ' va-fib-selected' : '') +
                                '" data-pos="' + pos + '">' + (pos + 1) + '</span>';
                        }
                        text = text.replace(placeholder, blankHtml);
                    }
                    html += '<p class="va-fib-passage">' + text + '</p>';
                    html += '</div>';

                    html += '<div class="va-fib-wordbank">';
                    html += '<div class="va-fib-wordbank-label">Word Bank</div>';
                    html += '<div class="va-fib-words">';
                    var usedWords = {};
                    for (var key in filledBlanks) {
                        if (key.indexOf('_checked') === -1 && filledBlanks[key]) {
                            usedWords[filledBlanks[key]] = true;
                        }
                    }
                    allWords.forEach(function(word) {
                        var isUsed = usedWords[word] === true;
                        html += '<button class="va-fib-word' + (isUsed ? ' va-fib-word-used' : '') + '" data-word="' +
                            escapeHtml(word) + '"' + (isUsed ? ' disabled' : '') + '>' + escapeHtml(word) + '</button>';
                    });
                    html += '</div>';
                    html += '</div>';

                    var allFilled = true;
                    for (var b = 0; b < blanks.length; b++) {
                        if (!filledBlanks[b]) { allFilled = false; break; }
                    }
                    if (allFilled) {
                        html += '<div class="va-fib-actions">';
                        html += '<button class="va-btn va-btn-primary" id="va-fib-check">Check Answers</button>';
                        html += '</div>';
                    }

                    html += '</div>';
                    optionsContainer.innerHTML = html;

                    var blankEls = optionsContainer.querySelectorAll('.va-fib-blank');
                    blankEls.forEach(function(el) {
                        el.addEventListener('click', function() {
                            var pos = parseInt(this.getAttribute('data-pos'));
                            if (filledBlanks[pos + '_checked']) return;
                            if (filledBlanks[pos]) {
                                delete filledBlanks[pos];
                                selectedBlankPos = pos;
                                renderFIBState();
                            } else {
                                selectedBlankPos = pos;
                                renderFIBState();
                            }
                        });
                    });

                    var wordEls = optionsContainer.querySelectorAll('.va-fib-word:not([disabled])');
                    wordEls.forEach(function(el) {
                        el.addEventListener('click', function() {
                            var word = this.getAttribute('data-word');
                            if (selectedBlankPos !== null) {
                                filledBlanks[selectedBlankPos] = word;
                                selectedBlankPos = null;
                                renderFIBState();
                            } else {
                                for (var b = 0; b < blanks.length; b++) {
                                    if (!filledBlanks[b]) {
                                        filledBlanks[b] = word;
                                        break;
                                    }
                                }
                                renderFIBState();
                            }
                        });
                    });

                    var fibCheckBtn = document.getElementById('va-fib-check');
                    if (fibCheckBtn) {
                        fibCheckBtn.addEventListener('click', function() {
                            var allCorrect = true;
                            for (var b = 0; b < blanks.length; b++) {
                                filledBlanks[b + '_checked'] = true;
                                if (filledBlanks[b] !== blanks[b].answer) {
                                    allCorrect = false;
                                }
                            }
                            if (allCorrect) {
                                playCorrectSound();
                                tryScoreCurrentQuestion();
                                if (q.id) saveAnswerToDatabase(q.id, 0, true);
                                renderFIBState();
                                showInteractiveFeedback(q, true);
                            } else {
                                playIncorrectSound();
                                renderFIBState();
                                // FIX-VA-SCORING-MODE: In first-attempt mode, show Next instead of resetting blanks.
                                if (config.scoringMode === 1) {
                                    showInteractiveFeedback(q, false);
                                    var fibCheckBtnRef = document.getElementById('va-fib-check');
                                    if (fibCheckBtnRef) fibCheckBtnRef.style.display = 'none';
                                } else {
                                    setTimeout(function() {
                                        for (var b = 0; b < blanks.length; b++) {
                                            if (filledBlanks[b] !== blanks[b].answer) {
                                                delete filledBlanks[b];
                                            }
                                            delete filledBlanks[b + '_checked'];
                                        }
                                        selectedBlankPos = null;
                                        renderFIBState();
                                    }, 1500);
                                }
                            }
                        });
                    }
                }

                var checkBtn = document.getElementById('va-check-answer-btn');
                if (checkBtn) checkBtn.style.display = 'none';
                renderFIBState();
            }

            function showInteractiveFeedback(q, isCorrect) {
                var feedbackContainer = document.getElementById('va-feedback-container');
                if (feedbackContainer) {
                    var feedbackClass = isCorrect ? 'va-feedback-correct' : 'va-feedback-incorrect';
                    var feedbackTitle = isCorrect ? 'Well done!' : 'Some answers were incorrect.';
                    feedbackContainer.innerHTML = '<div class="va-feedback ' + feedbackClass + '"><strong>' + feedbackTitle + '</strong>' +
                        (q.explanation ? '<p>' + escapeHtml(q.explanation) + '</p>' : '') + '</div>';
                    feedbackContainer.style.display = 'block';
                }
                // FIX-VA-VOICEOVER-DIAG (v1.0.103)
                console.log('[VA-DIAG] guard idx=0 q.type=' + (q && q.type) +
                    ' hasAD=' + !!(q && q.audioData) +
                    ' isArr=' + Array.isArray(q && q.audioData) +
                    ' adLen=' + ((q && q.audioData) ? q.audioData.length : 0) +
                    ' [0]=' + ((q && q.audioData && q.audioData[0]) ? ('set(' + q.audioData[0].length + ')') : 'EMPTY'));
                if (q.audioData && q.audioData[0]) {
                    playVoiceover(q.audioData[0]);
                }
                var nextBtn = document.getElementById('va-next-question-btn');
                if (nextBtn) {
                    nextBtn.style.display = 'block';
                    nextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                }
                var scoreEl = document.getElementById('va-quiz-score');
                if (scoreEl) scoreEl.textContent = 'Score: ' + score + '/' + quizData.length;
            }

            function checkAnswer() {
                var q = quizData[currentQuestionIndex];
                var type = q.type || 'mcq';

                if (type === 'ordering') {
                    checkOrderingAnswer(q);
                    return;
                }

                if (type === 'cardselect') {
                    checkCardSelectAnswer(q);
                    return;
                }

                if (selectedAnswer === null) return;
                var mcqLetters = ['A', 'B', 'C', 'D'];
                // FIX-VA-NULL-CORRECTANSWER (v1.0.130): use != null to guard against
                // correctAnswer:null from DB, which parseInt converts to NaN causing
                // every answer to be evaluated against an undefined letter (always false).
                var correctIdxRaw = q.correctAnswer != null
                    ? q.correctAnswer
                    : (q.correctIndex != null ? q.correctIndex : 0);
                var correctIdx = parseInt(correctIdxRaw, 10);
                if (isNaN(correctIdx) || correctIdx < 0) { correctIdx = 0; }
                var isCorrect = selectedLabel !== null
                    ? selectedLabel === mcqLetters[correctIdx]
                    : selectedAnswer === correctIdx;

                if (q.id) {
                    var originalIndex = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
                    saveAnswerToDatabase(q.id, originalIndex);
                }

                if (isCorrect) {
                    tryScoreCurrentQuestion();
                    playCorrectSound();
                } else {
                    playIncorrectSound();
                }

                $('.va-option').addClass('disabled');
                $('.va-option').each(function() {
                    var idx = parseInt($(this).data('index'), 10);
                    if (idx === correctIdx) {
                        $(this).addClass('correct');
                    } else if (idx === selectedAnswer && !isCorrect) {
                        $(this).addClass('incorrect');
                    }
                });

                var feedbackContainer = document.getElementById('va-feedback-container');
                if (feedbackContainer) {
                    var explanation = q.explanations ? q.explanations[selectedAnswer] : '';
                    feedbackContainer.innerHTML = '<div class="va-feedback ' + (isCorrect ? 'va-feedback-correct' : 'va-feedback-incorrect') + '">' +
                        '<strong>' + (isCorrect ? 'Correct!' : 'Incorrect') + '</strong>' +
                        (explanation ? '<p>' + escapeHtml(explanation) + '</p>' : '') + '</div>';
                    feedbackContainer.style.display = 'block';
                }

                // FIX-VA-VOICEOVER-DIAG (v1.0.103)
                console.log('[VA-DIAG] guard(mcq) idx=' + selectedAnswer + ' q.type=' + (q && q.type) +
                    ' hasAD=' + !!(q && q.audioData) +
                    ' isArr=' + Array.isArray(q && q.audioData) +
                    ' adLen=' + ((q && q.audioData) ? q.audioData.length : 0) +
                    ' [sel]=' + ((q && q.audioData && q.audioData[selectedAnswer]) ? ('set(' + q.audioData[selectedAnswer].length + ')') : 'EMPTY'));
                if (q.audioData && q.audioData[selectedAnswer]) {
                    playVoiceover(q.audioData[selectedAnswer]);
                }

                var checkBtn = document.getElementById('va-check-answer-btn');
                if (checkBtn) checkBtn.style.display = 'none';

                var tryAgainBtn = document.getElementById('va-try-again-btn');
                var nextBtn = document.getElementById('va-next-question-btn');

                if (isCorrect) {
                    if (tryAgainBtn) tryAgainBtn.style.display = 'none';
                    if (nextBtn) {
                        nextBtn.style.display = 'block';
                        nextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                    }
                } else {
                    // FIX-VA-SCORING-MODE: In first-attempt mode, show Next immediately instead of Try Again.
                    if (config.scoringMode === 1) {
                        if (tryAgainBtn) tryAgainBtn.style.display = 'none';
                        if (nextBtn) {
                            nextBtn.style.display = 'block';
                            nextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                        }
                    } else {
                        if (tryAgainBtn) tryAgainBtn.style.display = 'block';
                        if (nextBtn) nextBtn.style.display = 'none';
                    }
                }

                var scoreEl = document.getElementById('va-quiz-score');
                if (scoreEl) scoreEl.textContent = 'Score: ' + score + '/' + quizData.length;
            }

            function checkCardSelectAnswer(q) {
                if (selectedAnswer === null) return;

                var correctIdx = q.correctIndex !== undefined ? q.correctIndex : 0;
                var isCorrect = selectedAnswer === correctIdx;

                if (q.id) {
                    saveAnswerToDatabase(q.id, selectedAnswer, isCorrect);
                }

                if (isCorrect) {
                    tryScoreCurrentQuestion();
                    playCorrectSound();
                } else {
                    playIncorrectSound();
                }

                $('.va-card-option').addClass('va-disabled');

                var correctCard = $('.va-card-option[data-index="' + correctIdx + '"]');
                correctCard.addClass('va-card-correct');
                if (!isCorrect) {
                    var selectedCard = $('.va-card-option[data-index="' + selectedAnswer + '"]');
                    selectedCard.addClass('va-card-incorrect');
                }

                // FIX-VA-CARDSELECT-PERCARD-VOICEOVER (v1.0.110): when the question carries
                // a per-card explanations[] array (length === cards.length, supplied by the
                // server when generated/regenerated under v1.0.110+), use the explanation for
                // the card the student actually picked rather than always showing the correct
                // card's explanation. Legacy questions only have q.explanation (singular) — keep
                // showing that on both correct and incorrect (matches v1.0.109 text behaviour;
                // no answer-leak in text since it was already shown).
                var hasPerCardText = Array.isArray(q.explanations) && q.cards && q.explanations.length === q.cards.length;
                // FIX-VA-CARDSELECT-PERCARD-EDITOR (v1.0.116): if per-card text exists but
                // the specific slot is empty (teacher cleared it in the v1.0.116 editor),
                // fall back to the overall q.explanation rather than showing "Incorrect"
                // with no body. Filled per-card text still wins.
                // FIX-VA-CARDSELECT-RUNTIME-FALLBACK (v1.0.117): even when q.explanations[]
                // is entirely missing (un-healed legacy question whose data was wiped
                // before any v1.0.110+ heal/save), build a per-card "Incorrect. {label}
                // isn't quite the right fit..." string on the fly using the chosen card's
                // label. This guarantees the student NEVER sees the answer-revealing
                // q.explanation (singular, which describes the CORRECT card) on a wrong
                // click — even on the very oldest legacy questions in mum's DB that have
                // never been regenerated. On correct clicks we still show q.explanation
                // (the correct-card narration, which is now safe to reveal).
                var explanation;
                if (hasPerCardText) {
                    explanation = q.explanations[selectedAnswer] || q.explanation || '';
                } else if (isCorrect) {
                    explanation = q.explanation || '';
                } else {
                    var pickedCard = q.cards && q.cards[selectedAnswer];
                    var pickedLabel = (pickedCard && pickedCard.label) ? String(pickedCard.label).trim() : 'That option';
                    explanation = 'Incorrect. ' + pickedLabel + " isn't quite the right fit here. Have another look at the question and try a different option.";
                }

                var feedbackContainer = document.getElementById('va-feedback-container');
                if (feedbackContainer) {
                    feedbackContainer.innerHTML = '<div class="va-feedback ' + (isCorrect ? 'va-feedback-correct' : 'va-feedback-incorrect') + '">' +
                        '<strong>' + (isCorrect ? 'Correct!' : 'Incorrect') + '</strong>' +
                        (explanation ? '<p>' + escapeHtml(explanation) + '</p>' : '') + '</div>';
                    feedbackContainer.style.display = 'block';
                }

                // FIX-VA-VOICEOVER-DIAG (v1.0.103)
                console.log('[VA-DIAG] guard idx=' + selectedAnswer + ' q.type=' + (q && q.type) +
                    ' hasAD=' + !!(q && q.audioData) +
                    ' isArr=' + Array.isArray(q && q.audioData) +
                    ' adLen=' + ((q && q.audioData) ? q.audioData.length : 0) +
                    ' hasPerCardText=' + hasPerCardText);
                // FIX-VA-CARDSELECT-PERCARD-VOICEOVER (v1.0.110): when q.audioData is an array
                // aligned with cards (length === cards.length, supplied by the server when
                // generated/regenerated under v1.0.110+), play the audio for the card the
                // student actually picked. This is safe on incorrect because each clip narrates
                // its own card's explanation — never spoils the correct answer.
                //
                // Legacy questions only have q.audioData=[oneCorrectClip] — fall back to the
                // v1.0.109 gate (correct OR scoringMode===1) which protects against revealing
                // the correct-card narration after a wrong click. Mum's existing pre-v1.0.110
                // quizzes can be upgraded by clicking the "Regenerate audio" button in the
                // Moodle question editor, which re-calls the AI under the new prompt and
                // produces per-card explanations + per-card audio.
                // FIX-VA-CARDSELECT-CHIRP-FALLBACK (v1.0.118): four-way audio playback.
                // 1) Per-card audio slot ready -> play it.
                // 2) Per-card audio slot MISSING but per-card text exists -> on-demand
                //    Google Chirp top-up via /api/videoactivity-tts-single, cached
                //    in-memory for the rest of the attempt. NEVER browser Web Speech --
                //    Chirp HD only, project-wide rule for AI Video Activity.
                // 3) Legacy single-clip path (no per-card array): only safe to play
                //    on correct OR scoringMode===1 (would otherwise leak the answer).
                // 4) Wrong-click + legacy + normal scoring -> Chirp top-up using the
                //    synthesised "Incorrect. {label}..." explanation built above.
                // FIX-VA-CARDSELECT-VOICEOVER-PREFIX (v1.0.124): helper that guarantees the
                // text sent to the Chirp top-up TTS call always starts with "Correct." or
                // "Incorrect." so the student hears the verdict word before the explanation.
                // AI-generated per-card explanations should already start with those words per
                // the prompt, but this is a defensive guard for legacy data or prompt drift.
                var ensureVoiceoverPrefix = function(text, correct) {
                    var t = (text || '').trim();
                    if (!t) { return correct ? 'Correct.' : 'Incorrect.'; }
                    if (/^(Correct|Incorrect)/i.test(t)) { return t; }
                    return (correct ? 'Correct. ' : 'Incorrect. ') + t;
                };

                // FIX-VA-CARDSELECT-STALE-AUDIO (v1.0.124): capture the question index NOW
                // (synchronously) so the topUpMissingClipAndPlay .done() callback can bail
                // if the student has already moved to the next question by the time the
                // 10-15 s Chirp API call resolves.  Without this guard the synthesised
                // audio played over the top of the next (or later) question's content.
                var _guardQIdx = currentQuestionIndex;

                var hasPerCardAudio = Array.isArray(q.audioData) && q.cards && q.audioData.length === q.cards.length && q.audioData.length > 1;
                if (hasPerCardAudio && q.audioData[selectedAnswer]) {
                    playVoiceover(q.audioData[selectedAnswer]);
                } else if (hasPerCardAudio && !q.audioData[selectedAnswer] && explanation && explanation.trim().length > 0) {
                    topUpMissingClipAndPlay(q, selectedAnswer, ensureVoiceoverPrefix(explanation, isCorrect), _guardQIdx);
                } else if (!hasPerCardAudio && q.audioData && q.audioData[0] && (isCorrect || config.scoringMode === 1)) {
                    playVoiceover(q.audioData[0]);
                } else if (!hasPerCardAudio && !isCorrect && config.scoringMode !== 1 && explanation && explanation.trim().length > 0) {
                    topUpMissingClipAndPlay(q, selectedAnswer, ensureVoiceoverPrefix(explanation, false), _guardQIdx);
                }

                var checkBtn = document.getElementById('va-check-answer-btn');
                if (checkBtn) checkBtn.style.display = 'none';

                var tryAgainBtn = document.getElementById('va-try-again-btn');
                var nextBtn = document.getElementById('va-next-question-btn');

                if (isCorrect) {
                    if (tryAgainBtn) tryAgainBtn.style.display = 'none';
                    if (nextBtn) {
                        nextBtn.style.display = 'block';
                        nextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                    }
                } else {
                    // FIX-VA-SCORING-MODE: In first-attempt mode, show Next immediately instead of Try Again.
                    if (config.scoringMode === 1) {
                        if (tryAgainBtn) tryAgainBtn.style.display = 'none';
                        if (nextBtn) {
                            nextBtn.style.display = 'block';
                            nextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                        }
                    } else {
                        if (tryAgainBtn) tryAgainBtn.style.display = 'block';
                        if (nextBtn) nextBtn.style.display = 'none';
                    }
                }

                var scoreEl = document.getElementById('va-quiz-score');
                if (scoreEl) scoreEl.textContent = 'Score: ' + score + '/' + quizData.length;
            }

            function checkOrderingAnswer(q) {
                var currentOrder = q._currentOrder;
                var correctOrder = q._correctOrder;

                if (!currentOrder || !correctOrder) return;

                var isCorrect = arraysEqual(currentOrder, correctOrder);

                if (q.id) {
                    saveAnswerToDatabase(q.id, 0, isCorrect);
                }

                if (isCorrect) {
                    tryScoreCurrentQuestion();
                    playCorrectSound();

                    $('.va-order-item').addClass('va-order-correct');

                    showInteractiveFeedback(q, true);

                    var checkBtn = document.getElementById('va-check-answer-btn');
                    if (checkBtn) checkBtn.style.display = 'none';
                } else {
                    playIncorrectSound();

                    $('.va-order-item').each(function(idx) {
                        if (currentOrder[idx] === correctOrder[idx]) {
                            $(this).addClass('va-order-correct');
                        } else {
                            $(this).addClass('va-order-wrong');
                            $(this).addClass('va-shake');
                        }
                    });

                    var feedbackContainer = document.getElementById('va-feedback-container');
                    if (feedbackContainer) {
                        feedbackContainer.innerHTML = '<div class="va-feedback va-feedback-incorrect"><strong>Not quite right.</strong><p>Some items are in the wrong position. Try swapping them!</p></div>';
                        feedbackContainer.style.display = 'block';
                    }

                    // FIX-VA-SCORING-MODE: In first-attempt mode, reveal the correct order and show Next.
                    if (config.scoringMode === 1) {
                        var ordCheckBtn = document.getElementById('va-check-answer-btn');
                        if (ordCheckBtn) ordCheckBtn.style.display = 'none';
                        var ordNextBtn = document.getElementById('va-next-question-btn');
                        if (ordNextBtn) {
                            ordNextBtn.style.display = 'block';
                            ordNextBtn.textContent = (currentQuestionIndex < quizData.length - 1) ? 'Next Question' : 'Finish Quiz';
                        }
                    } else {
                        setTimeout(function() {
                            $('.va-order-item').removeClass('va-order-correct va-order-wrong va-shake');
                            // BUG-VA-ORD-FEEDBACK-STALE: Previously the "Not quite right" feedback remained
                            // visible permanently while the student re-arranged items after a wrong attempt.
                            // Clear it alongside the shake animation so the student sees a clean state while
                            // re-ordering. The feedback re-appears fresh on the next "Check Order" click if
                            // still incorrect.
                            var fc = document.getElementById('va-feedback-container');
                            if (fc) { fc.style.display = 'none'; fc.innerHTML = ''; }
                        }, 1500);
                    }
                }
            }

            function tryAgain() {
                if (audioElement) {
                    audioElement.pause();
                    audioElement = null;
                }

                var tryAgainBtn = document.getElementById('va-try-again-btn');
                if (tryAgainBtn) tryAgainBtn.style.display = 'none';

                var feedbackContainer = document.getElementById('va-feedback-container');
                if (feedbackContainer) feedbackContainer.style.display = 'none';

                var q = quizData[currentQuestionIndex];
                var type = q.type || 'mcq';

                if (type === 'cardselect') {
                    var checkBtn = document.getElementById('va-check-answer-btn');
                    if (checkBtn) { checkBtn.style.display = 'block'; checkBtn.disabled = true; }
                    selectedAnswer = null;
                    selectedLabel = null;

                    $('.va-card-option').removeClass('va-card-selected va-card-correct va-card-incorrect va-disabled');
                    $('.va-card-option').off('click').on('click', function() {
                        if ($(this).hasClass('va-disabled')) return;
                        $('.va-card-option').removeClass('va-card-selected');
                        $(this).addClass('va-card-selected');
                        selectedAnswer = parseInt($(this).data('index'), 10);
                        if (checkBtn) checkBtn.disabled = false;
                    });
                    return;
                }

                var checkBtn = document.getElementById('va-check-answer-btn');
                if (checkBtn) { checkBtn.style.display = 'block'; checkBtn.disabled = true; }
                selectedAnswer = null;
                // FIX-VA-TRYAGAIN-LABEL: Reset selectedLabel so stale letter
                // from the previous (wrong) attempt cannot be compared against
                // the correct answer after the student re-selects.
                selectedLabel = null;

                $('.va-option').removeClass('selected correct incorrect disabled');
                $('.va-option').off('click').on('click', function() {
                    if ($(this).hasClass('disabled')) return;
                    $('.va-option').removeClass('selected');
                    $(this).addClass('selected');
                    selectedAnswer = parseInt($(this).data('index'), 10);
                    selectedLabel = $(this).data('label') || null;
                    if (checkBtn) checkBtn.disabled = false;
                });
            }

            function playVoiceover(base64Audio) {
                // FIX-VA-VOICEOVER-DIAG (v1.0.103): always log entry to playVoiceover so
                // we can distinguish "guard short-circuited and we never tried" from
                // "tried but Audio() / play() silently failed".
                console.log('[VA-DIAG] playVoiceover called, base64Audio len=' + (base64Audio ? base64Audio.length : 0));
                if (audioElement) {
                    audioElement.pause();
                    audioElement = null;
                }
                try {
                    // FIX-VA-VOICEOVER-MIME: Server generates OGG_OPUS (not MP3).
                    // Using audio/mp3 MIME type caused silent playback failures on all types.
                    audioElement = new Audio('data:audio/ogg;base64,' + base64Audio);
                    var playPromise = audioElement.play();
                    if (playPromise && typeof playPromise.then === 'function') {
                        playPromise.then(function() {
                            console.log('[VA-DIAG] playVoiceover play() resolved');
                        }).catch(function(e) {
                            console.log('[VA] Voiceover playback failed:', e);
                        });
                    }
                } catch (e) {
                    console.log('[VA] Voiceover error:', e);
                }
            }

            // FIX-VA-CARDSELECT-CHIRP-FALLBACK (v1.0.118): on-demand single-clip TTS via
            // Google Chirp HD, used when q.audioData[selectedAnswer] is missing.
            // Triggered by checkCardSelectAnswer when (a) the question has a per-card
            // audio array but a specific slot is empty (server-side initial TTS failed
            // or the question was created before v1.0.117), or (b) a legacy single-clip
            // cardselect question is clicked wrong in normal scoring mode (the singular
            // q.audioData[0] would leak the correct answer so cannot be played). The
            // synthesised per-card "Incorrect. {label}..." text is sent to the SaaS,
            // which calls Google Chirp with the activity's configured voice and returns
            // base64 OGG_OPUS bytes that playVoiceover() consumes identically to
            // server-pre-generated clips. The returned clip is cached in-memory on
            // q.audioData[cardIdx] so subsequent attempts within the same page don't
            // re-call the API. PROJECT RULE for this plugin: NEVER fall back to the
            // browser's window.speechSynthesis API -- every voiceover must come from
            // Google Chirp HD so quality is uniform across cards.
            // FIX-VA-CARDSELECT-STALE-AUDIO (v1.0.124): guardIdx is the value of
            // currentQuestionIndex at the moment the student clicked a card.  If the
            // student navigates away before the Chirp API responds (~10-15 s), the
            // .done() callback skips playVoiceover() so the stale clip never interrupts
            // the next question.  The clip is still cached in q.audioData[cardIdx] so
            // a revisit within the same page load plays instantly.
            function topUpMissingClipAndPlay(q, cardIdx, text, guardIdx) {
                var voiceLanguage = config.voiceLanguage || 'en-AU';
                var voiceId = config.voiceStyle || 'Aoede';
                console.log('[VA-DIAG] Chirp top-up requested qid=' + (q && q.id) + ' card=' + cardIdx + ' textLen=' + (text ? text.length : 0) + ' voice=' + voiceId + ' guardIdx=' + guardIdx);
                ajaxCall('ttssingle', {
                    text: text,
                    voiceLanguage: voiceLanguage,
                    voiceId: voiceId
                }, 30000).done(function(response) {
                    if (response && response.ok && response.audioData) {
                        // Cache aligned with cards array. Pad with empty strings so
                        // q.audioData[cardIdx] lands at the right index even when the
                        // question started life with no audio array at all.
                        var targetLen = (q.cards && q.cards.length) ? q.cards.length : (cardIdx + 1);
                        if (!Array.isArray(q.audioData)) q.audioData = [];
                        while (q.audioData.length < targetLen) q.audioData.push('');
                        q.audioData[cardIdx] = response.audioData;
                        // FIX-VA-CARDSELECT-STALE-AUDIO (v1.0.124): only play if the
                        // student is still on the same question that triggered this call.
                        if (guardIdx !== undefined && currentQuestionIndex !== guardIdx) {
                            console.log('[VA-DIAG] Chirp top-up resolved but question changed (' + guardIdx + ' -> ' + currentQuestionIndex + ') — clip cached, not played');
                            return;
                        }
                        playVoiceover(response.audioData);
                    } else {
                        console.log('[VA] Chirp top-up failed:', (response && response.error) || 'unknown');
                    }
                }).fail(function(xhr, status, error) {
                    console.log('[VA] Chirp top-up request failed:', status, error);
                });
            }

            function nextQuestion() {
                if (audioElement) {
                    audioElement.pause();
                    audioElement = null;
                }

                currentQuestionIndex++;

                // Save current position AND score so Continue Attempt can resume accurately
                // FIX-VA-SCORE-RESTORE: Store JSON {q: questionIndex, s: score} so score is restored on continue
                if (currentAttemptId) {
                    localStorage.setItem('va_progress_' + config.cmid + '_' + currentAttemptId, JSON.stringify({q: currentQuestionIndex, s: score}));
                }

                if (currentQuestionIndex < quizData.length) {
                    showQuestion();
                } else {
                    showResults();
                }
            }

            function saveAnswerToDatabase(questionId, answerIndex, isCorrectOverride) {
                if (!currentAttemptId) return;

                var params = {
                    attemptid: currentAttemptId,
                    questionid: questionId,
                    answerindex: answerIndex !== undefined ? answerIndex : 0
                };

                // For non-MCQ types, send iscorrect directly.
                if (isCorrectOverride !== undefined) {
                    params.iscorrect = isCorrectOverride ? 1 : 0;
                }

                ajaxCall('saveanswer', params).done(function(response) {
                    if (response.ok) {
                        console.log('[VA] Answer saved successfully');
                    } else {
                        console.error('[VA] Failed to save answer:', response.error);
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Save answer request failed:', status, error);
                });
            }

            // ==========================================
            // RESULTS DISPLAY
            // ==========================================

            function showResults() {
                if (currentAttemptId) {
                    localStorage.removeItem('va_progress_' + config.cmid + '_' + currentAttemptId);
                    finishAttempt();
                }

                document.getElementById('va-quiz-player').style.display = 'none';

                var totalQuestions = quizData.length;
                var grade = totalQuestions > 0 ? (score / totalQuestions) * config.maxGrade : 0;
                var percentage = totalQuestions > 0 ? Math.round((score / totalQuestions) * 100) : 0;
                var incorrect = totalQuestions - score;
                var isPerfect = score === totalQuestions;
                var gradePass = config.gradePass ? parseFloat(config.gradePass) : 0;
                var maxGrade = config.maxGrade ? parseInt(config.maxGrade, 10) : 100;
                var hasPassingGrade = gradePass > 0;
                var hasPassed = hasPassingGrade && grade >= gradePass;

                // Performance tier
                var tier, title, message;
                if (isPerfect) {
                    tier = 'perfect';
                    title = 'Perfect Score!';
                    message = 'Outstanding! You\'ve mastered this topic completely.';
                } else if (hasPassed) {
                    tier = 'excellent';
                    title = 'Well Done!';
                    message = 'You\'ve met the passing grade. Great work!';
                } else if (percentage >= 80) {
                    tier = 'excellent';
                    title = 'Excellent Work!';
                    message = 'You\'ve demonstrated strong understanding of this topic.';
                } else if (percentage >= 60) {
                    tier = 'good';
                    title = 'Good Progress!';
                    message = 'You\'re on the right track. Review the explanations to strengthen your knowledge.';
                } else {
                    tier = 'needs-work';
                    title = 'Keep Practising!';
                    message = 'Review the explanations and try again to improve your score.';
                }

                // Grade pill
                var gradeMessage = '';
                if (hasPassingGrade) {
                    var earnedDisplay = grade % 1 === 0 ? grade.toFixed(0) : grade.toFixed(1);
                    var passDisplay = gradePass % 1 === 0 ? gradePass.toFixed(0) : gradePass.toFixed(1);
                    if (hasPassed) {
                        gradeMessage = '<div class="va-grade-result va-grade-passed">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                            '<span>Passing grade achieved: ' + earnedDisplay + '/' + maxGrade + ' (required: ' + passDisplay + ')</span>' +
                        '</div>';
                    } else {
                        gradeMessage = '<div class="va-grade-result va-grade-failed">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                            '<span>Passing grade not reached: ' + earnedDisplay + '/' + maxGrade + ' (required: ' + passDisplay + ')</span>' +
                        '</div>';
                    }
                }

                // Action buttons
                var attemptsUsed = (config.attemptsUsed || 0) + 1;
                var maxAttempts = config.maxAttempts || 0;
                var canRetake = config.isTeacher || maxAttempts === 0 || attemptsUsed < maxAttempts;
                var retakeLabel = config.isTeacher ? 'Preview Again' : 'Retake Quiz';
                if (!config.isTeacher && maxAttempts > 0 && canRetake) {
                    retakeLabel += ' (' + (maxAttempts - attemptsUsed) + ' remaining)';
                }
                var courseUrl = config.courseUrl || '#';
                var actionHtml = '';
                if (canRetake) {
                    actionHtml +=
                        '<button id="va-retake-btn" class="va-btn va-btn-primary">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                            retakeLabel +
                        '</button>';
                }
                actionHtml +=
                    '<a href="' + courseUrl + '" class="va-btn va-btn-secondary">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>' +
                        'Back to Course' +
                    '</a>';

                // Score ring (circumference = 2 * PI * 75 ~= 471)  -  r=75, cx=cy=90, viewBox 180x180
                var circumference = 471;
                var offset = circumference - (circumference * percentage / 100);

                var html =
                    '<div class="va-results-card">' +
                        '<div class="va-results-header">' +
                            '<span class="va-results-badge">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                                'Quiz Complete' +
                            '</span>' +
                        '</div>' +
                        '<div class="va-results-body">' +
                            '<div class="va-score-ring">' +
                                '<svg viewBox="0 0 180 180">' +
                                    '<defs>' +
                                        '<linearGradient id="vaScoreGradient" x1="0%" y1="0%" x2="100%" y2="100%">' +
                                            '<stop offset="0%" style="stop-color:#667eea" />' +
                                            '<stop offset="100%" style="stop-color:#764ba2" />' +
                                        '</linearGradient>' +
                                    '</defs>' +
                                    '<circle class="va-score-ring-bg" cx="90" cy="90" r="75" />' +
                                    '<circle class="va-score-ring-fill ' + (tier === 'needs-work' ? 'needs-work' : (tier === 'perfect' || tier === 'excellent' ? 'excellent' : '')) + '" cx="90" cy="90" r="75" data-target-offset="' + offset + '" />' +
                                '</svg>' +
                                '<div class="va-score-center">' +
                                    '<div class="va-score-percent ' + (tier === 'needs-work' ? 'needs-work' : (tier === 'perfect' || tier === 'excellent' ? 'excellent' : '')) + '" data-target-percent="' + percentage + '">0%</div>' +
                                    '<div class="va-score-label">' + score + ' / ' + totalQuestions + '</div>' +
                                '</div>' +
                            '</div>' +
                            '<h3 class="va-results-title">' + title + '</h3>' +
                            '<p class="va-results-message">' + message + '</p>' +
                            '<div class="va-results-stats">' +
                                '<div class="va-results-stat">' +
                                    '<div class="va-results-stat-value correct">' + score + '</div>' +
                                    '<div class="va-results-stat-label">Correct</div>' +
                                '</div>' +
                                '<div class="va-results-stat">' +
                                    '<div class="va-results-stat-value incorrect">' + incorrect + '</div>' +
                                    '<div class="va-results-stat-label">Incorrect</div>' +
                                '</div>' +
                                '<div class="va-results-stat">' +
                                    '<div class="va-results-stat-value">' + totalQuestions + '</div>' +
                                    '<div class="va-results-stat-label">Questions</div>' +
                                '</div>' +
                            '</div>' +
                            gradeMessage +
                            '<div class="va-results-actions">' +
                                actionHtml +
                            '</div>' +
                        '</div>' +
                    '</div>';

                var resultsSection = document.getElementById('va-results-section');
                if (resultsSection) {
                    resultsSection.innerHTML = html;
                    resultsSection.style.display = 'block';
                }

                // Animate the score ring and percentage counter
                setTimeout(function() {
                    var ringFill = document.querySelector('#va-results-section .va-score-ring-fill');
                    var percentEl = document.querySelector('#va-results-section .va-score-percent');
                    if (ringFill) {
                        var targetOffset = parseFloat(ringFill.getAttribute('data-target-offset'));
                        ringFill.style.strokeDashoffset = targetOffset;
                    }
                    if (percentEl) {
                        var targetPercent = parseInt(percentEl.getAttribute('data-target-percent'), 10);
                        var duration = 1000;
                        var startTime = null;
                        function animateCount(timestamp) {
                            if (!startTime) startTime = timestamp;
                            var elapsed = timestamp - startTime;
                            var progress = Math.min(elapsed / duration, 1);
                            var eased = 1 - Math.pow(1 - progress, 3);
                            var current = Math.round(eased * targetPercent);
                            percentEl.textContent = current + '%';
                            if (progress < 1) {
                                requestAnimationFrame(animateCount);
                            }
                        }
                        requestAnimationFrame(animateCount);
                    }
                }, 50);

                if (isPerfect) {
                    playLevelCompleteSound();
                    createConfetti();
                } else if (hasPassed) {
                    playLevelCompleteSound();
                } else {
                    playTryAgainSound();
                }
            }

            function finishAttempt() {
                if (!currentAttemptId) return;

                ajaxCall('finishattempt', {
                    attemptid: currentAttemptId
                }).done(function(response) {
                    if (response.ok) {
                        console.log('[VA] Attempt finished successfully');
                        config.attemptsUsed = (config.attemptsUsed || 0) + 1;
                        updateAttemptsBadge();
                        currentAttemptId = null;
                    } else {
                        console.error('[VA] Failed to finish attempt:', response.error);
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[VA] Finish attempt request failed:', status, error);
                });
            }

            function updateAttemptsBadge() {
                var used = config.attemptsUsed || 0;
                var max = config.maxAttempts || 0;
                var usedStr = config.attemptsUsedStr || 'Attempts Used';
                var unlimitedStr = config.attemptsUnlimitedStr || 'Unlimited';
                var label = usedStr + ': ' + used + (max > 0 ? ' / ' + max : ' (' + unlimitedStr + ')');
                document.querySelectorAll('.va-attempts-badge').forEach(function(el) {
                    var svg = el.querySelector('svg');
                    var svgClone = svg ? svg.cloneNode(true) : null;
                    el.innerHTML = '';
                    if (svgClone) { el.appendChild(svgClone); }
                    el.appendChild(document.createTextNode(' ' + label));
                });
            }

            function handleRetake() {
                document.getElementById('va-results-section').style.display = 'none';

                if (config.isTeacher) {
                    document.getElementById('va-ready-section').style.display = 'block';
                } else {
                    // FIX-VA-GATE: Re-show the video section so the watch gate is
                    // re-enforced on every retake attempt.  Previously called
                    // handleStartAttempt() directly, which bypassed the requirement
                    // to watch/listen again before attempting the quiz.
                    var retakeStartSection = document.getElementById('va-start-section');
                    if (retakeStartSection) {
                        retakeStartSection.style.display = 'block';
                        retakeStartSection.classList.remove('va-video-during-quiz');
                        // Restore controls hidden by showVideoDuringQuiz.
                        var retakeWatchProgress = document.getElementById('va-watch-progress');
                        if (retakeWatchProgress) retakeWatchProgress.style.display = '';
                        var retakeVideoActions = retakeStartSection.querySelector('.va-video-actions');
                        if (retakeVideoActions) retakeVideoActions.style.display = '';
                        var retakeCardTitle = retakeStartSection.querySelector('.va-card-title');
                        if (retakeCardTitle) retakeCardTitle.style.display = '';
                    }

                    // FIX-VA-RETAKE-FREE-VIDEO (v1.0.108): The student has just completed
                    // an attempt — they have already watched the video at least once.
                    // Re-imposing the watch gate (and seek-prevention) on every retake
                    // forces them to sit through the entire video again before they can
                    // reattempt the quiz, and locks the YouTube/audio scrubber so they
                    // cannot jump to the section they want to revise. Mum reported this
                    // as a blocker. Bypass the gate entirely on retake: mark the watch
                    // requirement as met and set maxWatchedPosition to a very large
                    // number so the existing seek-ahead snap-back never fires
                    // (`!watchRequirementMet && currentTime > maxWatchedPosition + 2`
                    // becomes false on both legs).
                    watchRequirementMet = true;
                    maxWatchedPosition = Number.MAX_SAFE_INTEGER;
                    var retakeFill = document.getElementById('va-watch-progress-fill');
                    if (retakeFill) retakeFill.style.width = '100%';
                    var retakeProgressText = document.getElementById('va-watch-progress-text');
                    if (retakeProgressText) retakeProgressText.style.display = 'none';

                    // FIX-VA-RETAKE-LOADING-BTN (v1.0.109): The Start/Continue button text
                    // was set to 'Loading...' by handleStartAttempt/handleContinueAttempt
                    // before the prior attempt's questions loaded. textContent assignment
                    // wiped both the play-triangle SVG and the proper label. After the
                    // attempt finishes and the student clicks Retake, this same DOM node
                    // is shown again still reading 'Loading...' with no icon. Two cases
                    // to handle: (a) PHP rendered va-start-quiz-btn (no in-progress on
                    // initial load) — the button id is correct, just restore innerHTML.
                    // (b) PHP rendered va-continue-attempt-btn (had in-progress on initial
                    // load) — the in-progress attempt is now completed (status=1) so the
                    // button must trigger a NEW attempt, not try to re-open the closed
                    // one. Re-id the node to 'va-start-quiz-btn' so the delegated click
                    // handler routes to handleStartAttempt (which calls 'startattempt' to
                    // create a fresh attempt) instead of handleContinueAttempt (which
                    // would post to a stale attemptid).
                    var staleContinueBtn = document.getElementById('va-continue-attempt-btn');
                    if (staleContinueBtn) {
                        staleContinueBtn.id = 'va-start-quiz-btn';
                        staleContinueBtn.removeAttribute('data-attemptid');
                    }
                    resetStartQuizButtonHtml(true);
                    enableQuizButton();
                }
            }

            // ==========================================
            // EVENT BINDINGS
            // ==========================================

            function handleMediaTypeChange() {
                var mediaType = $('#va-media-type').val() || 'video';
                var youtubeGroup = document.getElementById('va-youtube-url-group');
                var audioGroup = document.getElementById('va-audio-url-group');

                if (mediaType === 'audio') {
                    if (youtubeGroup) youtubeGroup.style.display = 'none';
                    if (audioGroup) audioGroup.style.display = 'block';
                } else {
                    if (youtubeGroup) youtubeGroup.style.display = 'block';
                    if (audioGroup) audioGroup.style.display = 'none';
                }
                updateGenerateButtonState();
            }

            function initAudioPlayer() {
                var audioPlayer = document.getElementById('va-audio-player');
                if (!audioPlayer) return;

                audioPlayer.addEventListener('ended', function() {
                    if (config.watchMode === 'all') {
                        watchRequirementMet = true;
                        enableQuizButton();
                        updateWatchProgress();
                    }
                });

                audioPlayer.addEventListener('timeupdate', function() {
                    if (!watchRequirementMet) {
                        maxWatchedPosition = Math.max(maxWatchedPosition, audioPlayer.currentTime);
                    }
                    if (config.watchMode === 'seconds') {
                        watchedSeconds = Math.floor(audioPlayer.currentTime);
                        checkWatchRequirement();
                        updateWatchProgress();
                    }
                });

                // Seek prevention: snap back if student tries to skip ahead.
                audioPlayer.addEventListener('seeking', function() {
                    if (!watchRequirementMet && audioPlayer.currentTime > maxWatchedPosition + 2) {
                        console.log('[VA] Audio seek-ahead blocked  -  snapping back to', maxWatchedPosition);
                        audioPlayer.currentTime = maxWatchedPosition;
                    }
                });

                audioPlayer.addEventListener('loadedmetadata', function() {
                    videoReady = true;
                    if (config.watchMode === 'none') {
                        watchRequirementMet = true;
                        enableQuizButton();
                        updateWatchProgress();
                    }
                });
            }

            function renderVaJobRoleChips() {
                var container = document.getElementById('va-job-role-chips');
                if (!container) return;
                container.innerHTML = vaSelectedJobRoles.map(function(role, idx) {
                    return '<div class="va-role-chip">' +
                        '<span>' + $('<span>').text(role).html() + '</span>' +
                        '<button type="button" class="va-chip-remove" data-idx="' + idx + '">\u00d7</button>' +
                        '</div>';
                }).join('');
                $(container).find('.va-chip-remove').on('click', function() {
                    vaSelectedJobRoles.splice(parseInt($(this).data('idx'), 10), 1);
                    renderVaJobRoleChips();
                });
                var input = document.getElementById('va-job-role-input');
                if (input) input.disabled = vaSelectedJobRoles.length >= 5;
            }

            function bindTeacherEvents() {
                $(document).on('input', '#va-youtube-url', handleYouTubeUrlInput);
                $(document).on('change', '#va-media-type', handleMediaTypeChange);
                $(document).on('change', '#va-watch-mode', handleWatchModeChange);
                $(document).on('change input', '#va-num-questions', updateCostDisplay);
                $(document).on('change input keyup paste', '#va-transcript', updateCostDisplay);
                $(document).on('change', '#va-voice-gender', function() { handleGenderChange('va'); });
                $(document).on('click', '#va-generate-btn', handleGenerate);

                $(document).on('click', '#va-preview-btn', startTeacherPreview);
                $(document).on('click', '#va-edit-btn', showEditSection);
                $(document).on('click', '#va-save-edits-btn', saveEdits);
                $(document).on('click', '#va-cancel-edits-btn', cancelEdits);
                $(document).on('click', '.va-delete-question-btn', function() {
                    var idx = parseInt($(this).data('index'), 10);
                    if (isNaN(idx) || !quizData) return;
                    if (quizData.length <= 1) {
                        alert('You must keep at least one question.');
                        return;
                    }
                    if (!confirm('Remove Question ' + (idx + 1) + '? This cannot be undone until you save.')) return;
                    quizData.splice(idx, 1);
                    buildEditForms();
                });
                $(document).on('click', '#va-ready-regenerate-btn', handleRegenerate);
                $(document).on('click', '#va-edit-regenerate-btn', handleEditRegenerate);
                $(document).on('click', '#va-regenerate-audio-btn', regenerateAudio);
                $(document).on('click', '#va-settings-btn', openSettingsModal);
                $(document).on('click', '#va-settings-close-btn', closeSettingsModal);
                $(document).on('click', '#va-settings-cancel-btn', closeSettingsModal);
                $(document).on('click', '#va-settings-save-btn', saveSettings);
                $(document).on('change', '#va-settings-voiceover-toggle', function() {
                    var el = document.getElementById('va-settings-voice-options');
                    if (el) {
                        el.style.display = $(this).is(':checked') ? 'block' : 'none';
                    }
                });
                $(document).on('change', '#va-settings-voice-gender', function() {
                    handleGenderChange('va-settings');
                });
                $(document).on('change', '#va-voiceover-toggle', function() {
                    var voiceSection = document.getElementById('va-voice-settings-section');
                    if (voiceSection) {
                        voiceSection.style.display = $(this).is(':checked') ? 'block' : 'none';
                    }
                    updateCostDisplay();
                });
                $(document).on('change', '#va-question-type', handleQuestionTypeChange);

                // Job level pills  -  multi-select toggle.
                $(document).on('click', '#va-job-level-pills .va-level-pill', function() {
                    var val = $(this).data('value');
                    var idx = vaSelectedJobLevels.indexOf(val);
                    if (idx > -1) {
                        vaSelectedJobLevels.splice(idx, 1);
                        $(this).removeClass('va-level-active');
                    } else {
                        vaSelectedJobLevels.push(val);
                        $(this).addClass('va-level-active');
                    }
                });

                // Job role chips  -  press Enter or comma to add, max 5.
                $(document).on('keydown', '#va-job-role-input', function(e) {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        var val = $(this).val().trim().replace(/,$/, '');
                        if (val && vaSelectedJobRoles.indexOf(val) === -1 && vaSelectedJobRoles.length < 5) {
                            vaSelectedJobRoles.push(val);
                            renderVaJobRoleChips();
                        }
                        $(this).val('');
                    }
                });
            }

            function bindStudentEvents() {
                $(document).on('click', '#va-start-quiz-btn', handleStartAttempt);
                $(document).on('click', '#va-continue-attempt-btn', handleContinueAttempt);
            }

            function bindQuizPlayerEvents() {
                $(document).on('click', '#va-check-answer-btn', checkAnswer);
                $(document).on('click', '#va-try-again-btn', tryAgain);
                $(document).on('click', '#va-next-question-btn', nextQuestion);
                $(document).on('click', '#va-retake-btn', handleRetake);
            }

            // ==========================================
            // INITIALIZATION
            // ==========================================

            console.log('[VA] Initializing AI Video Activity', {
                cmid: config.cmid,
                isTeacher: config.isTeacher,
                watchMode: config.watchMode,
                youtubeUrl: config.youtubeUrl
            });

            $('#va-form').on('submit', function(e) {
                e.preventDefault();
                return false;
            });

            bindQuizPlayerEvents();

            if (config.isTeacher) {
                bindTeacherEvents();
                fetchCredits();
                fetchIndustries();

                if (config.mediaType) {
                    $('#va-media-type').val(config.mediaType);
                    handleMediaTypeChange();
                }
                if (config.youtubeUrl) {
                    $('#va-youtube-url').val(config.youtubeUrl);
                    handleYouTubeUrlInput();
                }
                if (config.transcriptText) {
                    $('#va-transcript').val(config.transcriptText);
                }
                if (config.watchMode) {
                    $('#va-watch-mode').val(config.watchMode);
                    handleWatchModeChange();
                }
                if (config.watchSeconds) {
                    $('#va-watch-seconds').val(config.watchSeconds);
                }
                if (config.voiceoverEnabled) {
                    $('#va-voiceover-toggle').prop('checked', true);
                    var voiceSection = document.getElementById('va-voice-settings-section');
                    if (voiceSection) voiceSection.style.display = 'block';
                }
                if (config.voiceLanguage) {
                    $('#va-voice-language').val(config.voiceLanguage);
                }
                if (config.voiceGender) {
                    $('#va-voice-gender').val(config.voiceGender);
                    handleGenderChange('va');
                }
                if (config.voiceStyle) {
                    setTimeout(function() {
                        $('#va-voice-style').val(config.voiceStyle);
                    }, 100);
                }
                if (config.questionCount) {
                    $('#va-num-questions').val(config.questionCount);
                }
                updateCostDisplay();

                if (config.questionCount > 0) {
                    ajaxCall('getquestions').done(function(response) {
                        if (response.ok && response.questions && response.questions.length > 0) {
                            quizData = response.questions.map(function(q) {
                                return q;
                            });

                            document.getElementById('va-form-section').style.display = 'none';
                            document.getElementById('va-ready-section').style.display = 'block';

                            var summaryEl = document.getElementById('va-ready-summary');
                            if (summaryEl) {
                                summaryEl.textContent = quizData.length + ' questions ready.';
                            }
                            var vaTeacherEta = document.getElementById('va-teacher-eta');
                            if (vaTeacherEta) {
                                var vaWatchSec = 0;
                                if (config.watchMode === 'seconds' && config.watchSeconds > 0) vaWatchSec = config.watchSeconds;
                                else if (config.watchMode === 'all') vaWatchSec = 300;
                                var vaQuizSec = quizData.length * 90;
                                var vaTotalSec = vaWatchSec + vaQuizSec;
                                var vaMin = Math.ceil(vaTotalSec / 60);
                                var vaTimeStr = vaMin < 1 ? 'Under 1 minute' : (vaMin === 1 ? '~1 minute' : (vaMin < 60 ? '~' + vaMin + ' minutes' : '~' + Math.floor(vaMin / 60) + (Math.floor(vaMin / 60) === 1 ? ' hr ' : ' hrs ') + (vaMin % 60) + ' min'));
                                var vaMediaLabel = config.mediaType === 'audio' ? 'Audio' : 'Video';
                                var vaDetailStr = (config.watchMode !== 'none' ? vaMediaLabel + ' + ' : '') + quizData.length + ' quiz question' + (quizData.length !== 1 ? 's' : '');
                                var vaClockSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                                vaTeacherEta.innerHTML = '<div class="va-eta-banner">' +
                                    '<div class="va-eta-icon-wrap">' + vaClockSvg + '</div>' +
                                    '<div class="va-eta-body">' +
                                    '<span class="va-eta-label">Estimated completion time</span>' +
                                    '<span class="va-eta-time">' + vaTimeStr + '</span>' +
                                    '<span class="va-eta-detail">' + vaDetailStr + '</span>' +
                                    '</div></div>';
                            }
                            fetchCredits();
                        }
                    });
                }
            } else {
                bindStudentEvents();

                if (config.mediaType === 'audio') {
                    initAudioPlayer();
                } else {
                    var videoId = extractVideoId(config.youtubeUrl);
                    if (videoId) {
                        loadYouTubeAPI(function() {
                            createYouTubePlayer('va-youtube-player-container', videoId);
                        });
                    }
                }

                if (config.watchMode === 'none') {
                    watchRequirementMet = true;
                    enableQuizButton();
                }

                // FIX-VA-RETAKE-FREE-VIDEO (v1.0.108): On initial page load, if the
                // student already has at least one completed attempt OR an in-progress
                // attempt, bypass the watch gate and seek-prevention. Completed-attempt
                // means they previously watched; in-progress means they were already
                // past the gate when they started the attempt. In either case the
                // gate has nothing left to enforce — re-imposing it just locks the
                // scrubber and forces a re-watch they already did. The PHP buttons
                // are also rendered enabled in this case (see view.php) so this JS
                // bypass aligns with the server-rendered state.
                if (config.watchMode !== 'none' && (config.hasInProgress || config.hasPreviousAttempts)) {
                    watchRequirementMet = true;
                    maxWatchedPosition = Number.MAX_SAFE_INTEGER;
                    var initFill = document.getElementById('va-watch-progress-fill');
                    if (initFill) initFill.style.width = '100%';
                    var initProgressText = document.getElementById('va-watch-progress-text');
                    if (initProgressText) initProgressText.style.display = 'none';
                    enableQuizButton();
                }

                updateWatchProgress();

                if (config.hasInProgress) {
                    var continueBtn = document.getElementById('va-continue-attempt-btn');
                    if (continueBtn) {
                        continueBtn.setAttribute('data-attemptid', config.inProgressAttemptId);
                        continueBtn.style.display = 'block';
                    }
                }
            }
        }
    };
});
