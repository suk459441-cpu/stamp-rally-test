/* ==========================================================================
           Web Audio API 音声機能 & BGM整律
           ========================================================================== */
        let audioCtx = null;
        let isAudioOn = false;
        let bgmTimer = null;

        function initAudio() {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
        }

        function playTapSound() {
            initAudio();
            if (!isAudioOn) {
                isAudioOn = true;
                const btn = document.getElementById('audio-toggle');
                if (btn) btn.innerText = "🔊 BGM ON";
                startMelodicBGM();
            }

            if (!audioCtx) return;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(440, audioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(880, audioCtx.currentTime + 0.08);
            gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.08);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.08);
        }

        document.addEventListener('click', (e) => {
            initAudio();
            if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                playTapSound();
            }
        });

        function toggleAudio() {
            initAudio();
            const btn = document.getElementById('audio-toggle');
            if (isAudioOn) {
                stopMelodicBGM();
                btn.innerText = "🔊 BGM OFF";
                isAudioOn = false;
            } else {
                isAudioOn = true;
                btn.innerText = "🔊 BGM ON";
                startMelodicBGM();
            }
        }

        function startMelodicBGM() {
            if (!audioCtx || !isAudioOn) return;
            stopMelodicBGM();

            const notes = [
                220.00, 261.63, 329.63, 293.66, 261.63, 220.00, 196.00, 220.00,
                220.00, 329.63, 392.00, 440.00, 392.00, 329.63, 261.63, 220.00
            ];
            let noteIdx = 0;

            function playNextNote() {
                if (!isAudioOn) return;

                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(notes[noteIdx], audioCtx.currentTime);

                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.4);

                osc.connect(gain);
                gain.connect(audioCtx.destination);

                osc.start();
                osc.stop(audioCtx.currentTime + 0.4);

                noteIdx = (noteIdx + 1) % notes.length;
                bgmTimer = setTimeout(playNextNote, 300);
            }

            playNextNote();
        }

        function stopMelodicBGM() {
            if (bgmTimer) clearTimeout(bgmTimer);
        }

        function playSE(type) {
            initAudio();
            if (!audioCtx) return;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);

            if (type === 'stamp') {
                osc.frequency.setValueAtTime(523.25, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1046.50, audioCtx.currentTime + 0.2);
                gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
                gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.2);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.2);
            } else if (type === 'scan') {
                osc.type = 'sine';
                osc.frequency.setValueAtTime(300, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1200, audioCtx.currentTime + 0.3);
                gain.gain.setValueAtTime(0.25, audioCtx.currentTime);
                gain.gain.linearRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.3);
            }
        }

