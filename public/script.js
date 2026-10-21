/**
 * AL-NIKAH MUSLIM MATRIMONIAL - MASTER JAVASCRIPT
 * Strictly Vanilla JavaScript, Zero Frameworks, Performance-First
 * Features: Mobile Nav, Accessible Dropdowns, Hero Slider, Success Slider,
 * Profile Modals, Filter Submission, and Form Validation.
 */

document.addEventListener('DOMContentLoaded', () => {
  'use strict';

  // --------------------------------------------------------------------------
  // 1. RESPONSIVE MOBILE NAVIGATION & ABOUT DROPDOWN
  // --------------------------------------------------------------------------
  const mobileToggle = document.getElementById('mobile-menu-toggle');
  const navMenu = document.getElementById('primary-nav-menu');
  const aboutDropdownItem = document.getElementById('about-dropdown-item');
  const aboutMenuButton = document.getElementById('about-menu-button');

  if (mobileToggle && navMenu) {
    mobileToggle.addEventListener('click', () => {
      const isExpanded = mobileToggle.getAttribute('aria-expanded') === 'true';
      mobileToggle.setAttribute('aria-expanded', String(!isExpanded));
      navMenu.classList.toggle('is-active');
    });

    // Close menu when clicking outside or clicking any nav link (except dropdown button)
    document.addEventListener('click', (e) => {
      if (!navMenu.contains(e.target) && !mobileToggle.contains(e.target)) {
        navMenu.classList.remove('is-active');
        mobileToggle.setAttribute('aria-expanded', 'false');
      }
    });

    navMenu.querySelectorAll('.nav-link:not(.dropdown-toggle), .dropdown-link, .btn-nav-quote').forEach((link) => {
      link.addEventListener('click', () => {
        navMenu.classList.remove('is-active');
        mobileToggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // Functional About Dropdown
  if (aboutDropdownItem && aboutMenuButton) {
    const toggleDropdown = (open) => {
      const shouldOpen = typeof open === 'boolean' ? open : !aboutDropdownItem.classList.contains('is-open');
      if (shouldOpen) {
        aboutDropdownItem.classList.add('is-open');
        aboutMenuButton.setAttribute('aria-expanded', 'true');
      } else {
        aboutDropdownItem.classList.remove('is-open');
        aboutMenuButton.setAttribute('aria-expanded', 'false');
      }
    };

    // Open on hover for desktop screens
    aboutDropdownItem.addEventListener('mouseenter', () => {
      if (window.innerWidth > 860) {
        toggleDropdown(true);
      }
    });

    aboutDropdownItem.addEventListener('mouseleave', () => {
      if (window.innerWidth > 860) {
        toggleDropdown(false);
      }
    });

    aboutMenuButton.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleDropdown();
    });

    // Close dropdown when pressing Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && aboutDropdownItem.classList.contains('is-open')) {
        toggleDropdown(false);
        aboutMenuButton.focus();
      }
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
      if (!aboutDropdownItem.contains(e.target)) {
        toggleDropdown(false);
      }
    });
  }

  // --------------------------------------------------------------------------
  // 2. HERO IMAGE SLIDER (3 SLIDES, TOUCH-FRIENDLY, AUTO-PLAY WITH CONTROLS PAUSE)
  // --------------------------------------------------------------------------
  const heroSlider = document.getElementById('hero-slider');
  if (heroSlider) {
    const slides = heroSlider.querySelectorAll('.slide');
    const dots = heroSlider.querySelectorAll('.slider-dot');
    const prevBtn = document.getElementById('slider-prev');
    const nextBtn = document.getElementById('slider-next');
    const controls = heroSlider.querySelector('.slider-controls');
    let currentIndex = 0;
    let autoPlayTimer = null;
    const autoPlayInterval = 5000;

    const goToSlide = (index) => {
      if (index < 0) {
        currentIndex = slides.length - 1;
      } else if (index >= slides.length) {
        currentIndex = 0;
      } else {
        currentIndex = index;
      }

      slides.forEach((slide, i) => {
        const isActive = i === currentIndex;
        slide.classList.toggle('active', isActive);
        slide.setAttribute('aria-hidden', String(!isActive));
      });

      dots.forEach((dot, i) => {
        const isSelected = i === currentIndex;
        dot.classList.toggle('active', isSelected);
        if (isSelected) {
          dot.setAttribute('aria-current', 'true');
        } else {
          dot.removeAttribute('aria-current');
        }
      });
    };

    const nextSlide = () => goToSlide(currentIndex + 1);
    const prevSlide = () => goToSlide(currentIndex - 1);

    if (nextBtn) {
      nextBtn.addEventListener('click', (e) => {
        e.preventDefault();
        nextSlide();
        resetTimer();
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', (e) => {
        e.preventDefault();
        prevSlide();
        resetTimer();
      });
    }

    dots.forEach((dot, i) => {
      dot.addEventListener('click', (e) => {
        e.preventDefault();
        goToSlide(i);
        resetTimer();
      });
    });

    // Auto Play with Pause when hovering navigation controls
    const startTimer = () => {
      if (!autoPlayTimer) {
        autoPlayTimer = setInterval(nextSlide, autoPlayInterval);
      }
    };

    const stopTimer = () => {
      if (autoPlayTimer) {
        clearInterval(autoPlayTimer);
        autoPlayTimer = null;
      }
    };

    const resetTimer = () => {
      stopTimer();
      startTimer();
    };

    if (controls) {
      controls.addEventListener('mouseenter', stopTimer);
      controls.addEventListener('mouseleave', startTimer);
    }

    startTimer();

    // Touch Swiping Support (Passive listeners for smooth performance)
    let touchStartX = 0;
    let touchEndX = 0;

    heroSlider.addEventListener('touchstart', (e) => {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });

    heroSlider.addEventListener('touchend', (e) => {
      touchEndX = e.changedTouches[0].screenX;
      const swipeDistance = touchEndX - touchStartX;
      if (Math.abs(swipeDistance) > 40) {
        if (swipeDistance > 0) {
          prevSlide();
        } else {
          nextSlide();
        }
        resetTimer();
      }
    }, { passive: true });
  }



  // --------------------------------------------------------------------------
  // 3.5 BRIDE & GROOM PROFILES SLIDER & FILTER
  // --------------------------------------------------------------------------
  // 3.5 BRIDE & GROOM PROFILE CAROUSELS (BRIDES TOP, GROOMS BOTTOM)
  // --------------------------------------------------------------------------
  function initProfileCarousel(config) {
    const viewport = document.getElementById(config.viewportId);
    const track = document.getElementById(config.trackId);
    const prevBtn = document.getElementById(config.prevBtnId);
    const nextBtn = document.getElementById(config.nextBtnId);
    const dotsWrap = document.getElementById(config.dotsWrapId);

    if (!viewport || !track) return;

    let currentSlide = 0;
    let autoPlayTimer = null;

    const getVisibleCards = () => {
      return Array.from(track.querySelectorAll('.profile-card-custom')).filter(card => {
        return card.style.display !== 'none';
      });
    };

    const getItemsPerView = () => {
      const w = window.innerWidth;
      if (w <= 520) return 1;
      if (w <= 768) return 2;
      return 3;
    };

    const getMaxIndex = () => {
      const visibleCards = getVisibleCards();
      const perView = getItemsPerView();
      return Math.max(0, visibleCards.length - perView);
    };

    const updateSlider = (index) => {
      const visibleCards = getVisibleCards();
      const maxIdx = getMaxIndex();

      if (index < 0) {
        currentSlide = maxIdx;
      } else if (index > maxIdx) {
        currentSlide = 0;
      } else {
        currentSlide = index;
      }

      if (visibleCards.length === 0) {
        track.style.transform = 'translateX(0px)';
        return;
      }

      const cardEl = visibleCards[0];
      const cardRect = cardEl.getBoundingClientRect();
      const trackStyle = window.getComputedStyle(track);
      const gap = parseFloat(trackStyle.gap) || 24;
      const step = cardRect.width + gap;
      const offset = currentSlide * step;

      track.style.transform = `translateX(-${offset}px)`;

      if (dotsWrap) {
        const dots = dotsWrap.querySelectorAll('.profiles-dot');
        dots.forEach((dot, idx) => {
          const isCurrent = idx === currentSlide;
          dot.classList.toggle('active', isCurrent);
          if (isCurrent) {
            dot.setAttribute('aria-current', 'true');
          } else {
            dot.removeAttribute('aria-current');
          }
        });
      }
    };

    const renderDots = () => {
      if (!dotsWrap) return;
      dotsWrap.innerHTML = '';
      const maxIdx = getMaxIndex();
      const totalDots = maxIdx + 1;

      if (totalDots <= 1) {
        dotsWrap.style.display = 'none';
        return;
      }
      dotsWrap.style.display = 'flex';

      for (let i = 0; i < totalDots; i++) {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = `profiles-dot ${i === currentSlide ? 'active' : ''}`;
        dot.setAttribute('aria-label', `Go to slide ${i + 1}`);
        dot.addEventListener('click', () => {
          stopAutoPlay();
          updateSlider(i);
          startAutoPlay();
        });
        dotsWrap.appendChild(dot);
      }
    };

    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        stopAutoPlay();
        updateSlider(currentSlide + 1);
        startAutoPlay();
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        stopAutoPlay();
        updateSlider(currentSlide - 1);
        startAutoPlay();
      });
    }

    let pStartX = 0;
    viewport.addEventListener('touchstart', (e) => {
      pStartX = e.changedTouches[0].screenX;
      stopAutoPlay();
    }, { passive: true });

    viewport.addEventListener('touchend', (e) => {
      const pEndX = e.changedTouches[0].screenX;
      const diff = pEndX - pStartX;
      if (Math.abs(diff) > 40) {
        if (diff > 0) updateSlider(currentSlide - 1);
        else updateSlider(currentSlide + 1);
      }
      startAutoPlay();
    }, { passive: true });

    const startAutoPlay = () => {
      stopAutoPlay();
      autoPlayTimer = setInterval(() => {
        updateSlider(currentSlide + 1);
      }, config.autoPlayDelay || 4500);
    };

    const stopAutoPlay = () => {
      if (autoPlayTimer) {
        clearInterval(autoPlayTimer);
        autoPlayTimer = null;
      }
    };

    viewport.addEventListener('mouseenter', stopAutoPlay);
    viewport.addEventListener('mouseleave', startAutoPlay);

    window.addEventListener('resize', () => {
      renderDots();
      updateSlider(currentSlide);
    });

    renderDots();
    updateSlider(0);
    startAutoPlay();
  }

  // Initialize Row 1 (Brides) & Row 2 (Grooms)
  initProfileCarousel({
    viewportId: 'brides-slider',
    trackId: 'brides-slider-track',
    prevBtnId: 'brides-slider-prev',
    nextBtnId: 'brides-slider-next',
    dotsWrapId: 'brides-slider-dots',
    autoPlayDelay: 4200
  });

  initProfileCarousel({
    viewportId: 'grooms-slider',
    trackId: 'grooms-slider-track',
    prevBtnId: 'grooms-slider-prev',
    nextBtnId: 'grooms-slider-next',
    dotsWrapId: 'grooms-slider-dots',
    autoPlayDelay: 5000
  });

  // Global sendInterestAlert handler
  window.sendInterestAlert = function(name, btn) {
    var candidateName = name && name.trim() !== '' ? name : 'the candidate';
    if (typeof toastr !== 'undefined') {
      toastr.success('Your interest has been sent to ' + candidateName + '. Our matrimonial advisor will facilitate communication upon reciprocal acceptance.', 'Interest Sent Successfully!');
    } else {
      alert('Alhamdulillah! Your interest has been sent to ' + candidateName + '. Our matrimonial advisor will facilitate communication upon reciprocal acceptance.');
    }
    if (btn) {
      btn.classList.add('sent');
      btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg> Interest Sent';
      btn.disabled = true;
    }
  };

  // --------------------------------------------------------------------------
  // 4. BRIDE & GROOM PROFILES MODAL (VIEW PROFILE INTERACTIONS)
  // --------------------------------------------------------------------------
  const profileModal = document.getElementById('profile-modal');
  const modalCloseBtn = document.getElementById('modal-close-btn');

  if (profileModal) {
    const modalImg = document.getElementById('modal-img');
    const modalBadge = document.getElementById('modal-badge');
    const modalTitle = document.getElementById('modal-profile-title');
    const modalId = document.getElementById('modal-id');
    const modalAge = document.getElementById('modal-age');
    const modalEdu = document.getElementById('modal-edu');
    const modalCity = document.getElementById('modal-city');
    const modalStatus = document.getElementById('modal-status');

    const openProfileModal = (data) => {
      if (modalImg) modalImg.src = data.img || '';
      if (modalBadge) modalBadge.textContent = `${data.type} Profile`;
      if (modalTitle) modalTitle.textContent = data.name || 'Profile';
      if (modalId) modalId.textContent = `ID: ${data.id || ''}`;
      if (modalAge) modalAge.textContent = `${data.age} Years`;
      if (modalEdu) modalEdu.textContent = data.edu || 'Higher Education';
      if (modalCity) modalCity.textContent = data.city || 'Lucknow';
      if (modalStatus) modalStatus.textContent = data.status || 'Never Married';

      profileModal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
      if (modalCloseBtn) modalCloseBtn.focus();
    };

    const closeProfileModal = () => {
      profileModal.setAttribute('hidden', '');
      document.body.style.overflow = '';
    };

    // Event Delegation for View Profile Buttons
    document.addEventListener('click', (e) => {
      const targetBtn = e.target.closest('.view-profile-btn');
      if (targetBtn && targetBtn.tagName === 'BUTTON' && !targetBtn.getAttribute('href')) {
        const data = {
          id: targetBtn.dataset.profileId,
          name: targetBtn.dataset.name,
          type: targetBtn.dataset.type,
          age: targetBtn.dataset.age,
          edu: targetBtn.dataset.edu,
          city: targetBtn.dataset.city,
          status: targetBtn.dataset.status,
          img: targetBtn.dataset.img
        };
        openProfileModal(data);
      }
    });

    if (modalCloseBtn) {
      modalCloseBtn.addEventListener('click', closeProfileModal);
    }

    profileModal.addEventListener('click', (e) => {
      if (e.target === profileModal) {
        closeProfileModal();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !profileModal.hasAttribute('hidden')) {
        closeProfileModal();
      }
    });
  }

  // --------------------------------------------------------------------------
  // 5. BRIDE & GROOM FILTER PANEL & "LET'S BEGIN" SUBMISSION
  // --------------------------------------------------------------------------
  const filterForm = document.getElementById('matrimonial-filter-form');
  const filterResultsModal = document.getElementById('filter-results-modal');
  const filterValidationMsg = document.getElementById('filter-validation-msg');
  const filterModalCloseBtn = document.getElementById('filter-modal-close-btn');
  const filterDismissBtn = document.getElementById('filter-close-dismiss-btn');
  const filterTagsContainer = document.getElementById('filter-tags-container');

  if (filterForm) {
    filterForm.addEventListener('submit', (e) => {
      e.preventDefault();

      const lookingFor = filterForm.lookingFor.value.trim();
      const firqa = filterForm.firqa.value.trim();
      const maslak = filterForm.maslak.value.trim();
      const caste = filterForm.caste.value.trim();
      const maritalStatus = filterForm.maritalStatus.value.trim();

      // Validation for required field "I Am Looking For"
      if (!lookingFor) {
        if (filterValidationMsg) {
          filterValidationMsg.textContent = 'Please select whether you are looking for a Bride or Groom.';
        }
        filterForm.lookingFor.focus();
        return;
      }

      if (filterValidationMsg) {
        filterValidationMsg.textContent = '';
      }

      // Populate results modal tags
      if (filterTagsContainer) {
        filterTagsContainer.innerHTML = '';
        const tags = [
          `Looking for: ${lookingFor}`,
          `Firqa: ${firqa}`,
          `Maslak: ${maslak}`,
          `Caste: ${caste}`,
          `Status: ${maritalStatus}`
        ];

        tags.forEach(tagText => {
          const span = document.createElement('span');
          span.className = 'filter-tag';
          span.textContent = tagText;
          filterTagsContainer.appendChild(span);
        });
      }

      // Show Results Demonstration Modal
      if (filterResultsModal) {
        filterResultsModal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
      }
    });

    const closeFilterModal = () => {
      if (filterResultsModal) {
        filterResultsModal.setAttribute('hidden', '');
        document.body.style.overflow = '';
      }
    };

    if (filterModalCloseBtn) filterModalCloseBtn.addEventListener('click', closeFilterModal);
    if (filterDismissBtn) filterDismissBtn.addEventListener('click', closeFilterModal);

    if (filterResultsModal) {
      filterResultsModal.addEventListener('click', (e) => {
        if (e.target === filterResultsModal) closeFilterModal();
      });
    }
  }

  // --------------------------------------------------------------------------
  // 6. GENERIC ALERT / FEEDBACK DIALOG
  // --------------------------------------------------------------------------
  const alertModal = document.getElementById('alert-modal');
  const alertTitle = document.getElementById('alert-modal-title');
  const alertMsg = document.getElementById('alert-modal-msg');
  const alertOkBtn = document.getElementById('alert-modal-ok-btn');
  const alertCloseBtn = document.getElementById('alert-modal-close-btn');

  const showAlert = (title, message) => {
    if (alertModal && alertTitle && alertMsg) {
      alertTitle.textContent = title;
      alertMsg.textContent = message;
      alertModal.classList.remove('is-closing');
      alertModal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
      if (alertOkBtn) alertOkBtn.focus();
    }
  };

  const closeAlert = () => {
    if (alertModal && !alertModal.hasAttribute('hidden')) {
      alertModal.classList.add('is-closing');
      setTimeout(() => {
        alertModal.setAttribute('hidden', '');
        alertModal.classList.remove('is-closing');
        document.body.style.overflow = '';
      }, 220);
    }
  };

  if (alertOkBtn) alertOkBtn.addEventListener('click', closeAlert);
  if (alertCloseBtn) alertCloseBtn.addEventListener('click', closeAlert);
  if (alertModal) {
    alertModal.addEventListener('click', (e) => {
      if (e.target === alertModal) closeAlert();
    });
  }

  // --------------------------------------------------------------------------
  // 6.5. YOUTUBE VIDEO POPUP MODAL (WITH FULLSCREEN & BIG SCREEN TOGGLE)
  // --------------------------------------------------------------------------
  const videoModal = document.getElementById('video-modal');
  const videoModalBox = document.getElementById('video-modal-box');
  const videoIframe = document.getElementById('video-modal-iframe');
  const videoTitle = document.getElementById('video-modal-title');
  const videoCloseBtn = document.getElementById('video-modal-close-btn');
  const videoDoneBtn = document.getElementById('video-modal-done-btn');
  const videoFullscreenBtn = document.getElementById('video-modal-fullscreen-btn');
  const videoExternalLink = document.getElementById('video-modal-external-link');
  const ytSignupBtn = document.getElementById('btn-yt-signup');
  const ytLoginBtn = document.getElementById('btn-yt-login');

  if (videoModal && videoIframe) {
    let isBigScreen = false;

    const openVideoModal = (videoId, title, externalUrl) => {
      if (videoTitle) videoTitle.textContent = title || 'Alnikah Video Guide';
      if (videoExternalLink) videoExternalLink.href = externalUrl || `https://www.youtube.com/watch?v=${videoId}`;

      // Set YouTube embed with autoplay and controls
      videoIframe.src = `https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1&rel=0&enablejsapi=1`;

      videoModal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
      if (videoCloseBtn) videoCloseBtn.focus();
    };

    const closeVideoModal = () => {
      // Clear iframe source to immediately stop playing video/audio
      videoIframe.src = '';
      videoModal.setAttribute('hidden', '');
      document.body.style.overflow = '';

      if (isBigScreen) {
        toggleBigScreen(false);
      }
      if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
      }
    };

    const toggleBigScreen = (forceState) => {
      const nextState = typeof forceState === 'boolean' ? forceState : !isBigScreen;
      isBigScreen = nextState;

      if (videoModalBox) {
        videoModalBox.classList.toggle('is-big-screen', isBigScreen);
      }

      if (videoFullscreenBtn) {
        const iconExpand = videoFullscreenBtn.querySelector('.icon-expand');
        const iconCompress = videoFullscreenBtn.querySelector('.icon-compress');
        const textLabel = videoFullscreenBtn.querySelector('.btn-text-label');

        if (iconExpand && iconCompress) {
          iconExpand.style.display = isBigScreen ? 'none' : 'block';
          iconCompress.style.display = isBigScreen ? 'block' : 'none';
        }
        if (textLabel) {
          textLabel.textContent = isBigScreen ? 'Normal View' : 'Big Screen';
        }
      }

      // Also trigger browser native fullscreen if available for maximum immersion
      if (typeof forceState !== 'boolean') {
        if (isBigScreen && !document.fullscreenElement && videoModalBox && videoModalBox.requestFullscreen) {
          videoModalBox.requestFullscreen().catch(() => {});
        } else if (!isBigScreen && document.fullscreenElement && document.exitFullscreen) {
          document.exitFullscreen().catch(() => {});
        }
      }
    };

    // Keep state synced with browser native fullscreen exit
    document.addEventListener('fullscreenchange', () => {
      if (!document.fullscreenElement && isBigScreen) {
        toggleBigScreen(false);
      }
    });

    if (videoFullscreenBtn) {
      videoFullscreenBtn.addEventListener('click', () => toggleBigScreen());
    }

    if (videoCloseBtn) videoCloseBtn.addEventListener('click', closeVideoModal);
    if (videoDoneBtn) videoDoneBtn.addEventListener('click', closeVideoModal);

    videoModal.addEventListener('click', (e) => {
      if (e.target === videoModal) closeVideoModal();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !videoModal.hasAttribute('hidden')) {
        closeVideoModal();
      }
    });

    // Attach click events to the YouTube CTA buttons
    const attachVideoBtn = (btn, defaultId, defaultTitle) => {
      if (!btn) return;
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const videoId = btn.getAttribute('data-video-id') || defaultId;
        const title = btn.getAttribute('data-video-title') || defaultTitle;
        const url = btn.getAttribute('href');
        openVideoModal(videoId, title, url);
      });
    };

    attachVideoBtn(ytSignupBtn, '24a8WqPYV3o', 'For NEW user how to Signup - Alnikah Registration');
    attachVideoBtn(ytLoginBtn, '_EZ46l7B-rc', 'For Registered user how to - Login alnikahmarriage.com');
  }

  // --------------------------------------------------------------------------
  // 7. INQUIRY / CONTACT FORM VALIDATION (FOOTER)
  // --------------------------------------------------------------------------
  const inquiryForm = document.getElementById('footer-inquiry-form');
  const inquiryStatusMsg = document.getElementById('inquiry-status-msg');

  if (inquiryForm) {
    inquiryForm.addEventListener('submit', (e) => {
      e.preventDefault();

      const name = inquiryForm.name.value.trim();
      const phone = inquiryForm.phone.value.trim();
      const proposalType = inquiryForm.proposalType.value.trim();

      if (!name || name.length < 3) {
        if (inquiryStatusMsg) inquiryStatusMsg.textContent = 'Please enter your valid full name.';
        inquiryForm.name.focus();
        return;
      }

      if (!phone || phone.length < 8) {
        if (inquiryStatusMsg) inquiryStatusMsg.textContent = 'Please enter a valid phone or mobile number.';
        inquiryForm.phone.focus();
        return;
      }

      if (!proposalType) {
        if (inquiryStatusMsg) inquiryStatusMsg.textContent = 'Please specify if the proposal is for Bride or Groom.';
        inquiryForm.proposalType.focus();
        return;
      }

      // Success Demonstration (Front-End)
      if (inquiryStatusMsg) inquiryStatusMsg.textContent = '';
      inquiryForm.reset();
      showAlert('Inquiry Received', 'JazakAllah Khair! Your confidential matchmaking inquiry has been registered. Our senior family consultant will call you shortly.');
    });
  }

  // --------------------------------------------------------------------------
  // 7.5. CONTACT PAGE FEEDBACK FORM VALIDATION & SUBMISSION
  // --------------------------------------------------------------------------
  const feedbackForm = document.getElementById('feedback-form');
  if (feedbackForm) {
    feedbackForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (!feedbackForm.checkValidity()) {
        feedbackForm.reportValidity();
        return;
      }

      const nameInput = feedbackForm.querySelector('input[name="name"]');
      const phoneInput = feedbackForm.querySelector('input[name="phone"]');
      const emailInput = feedbackForm.querySelector('input[name="email"]');
      const submitBtn = feedbackForm.querySelector('#btn-feedback-submit');

      const name = nameInput ? nameInput.value.trim() : '';
      const phone = phoneInput ? phoneInput.value.trim() : '';
      const email = emailInput ? emailInput.value.trim() : '';

      if (!name) {
        if (nameInput) nameInput.reportValidity();
        return;
      }

      if (!phone || phone.length < 10) {
        if (phoneInput) {
          phoneInput.setCustomValidity('Please enter a valid 10-digit phone number');
          phoneInput.reportValidity();
        }
        return;
      }

      const originalBtnText = submitBtn ? submitBtn.textContent : 'Submit Now';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
      }

      try {
        const formData = new FormData(feedbackForm);
        const actionUrl = feedbackForm.getAttribute('action') || '/contact-us';

        const response = await fetch(actionUrl, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        const data = await response.json().catch(() => ({}));

        if (response.ok && data.success !== false) {
          feedbackForm.reset();
          showAlert('Feedback Received', data.message || 'JazakAllah Khair! Your message has been received. Our team will contact you shortly.');
        } else {
          const errMsg = data.message || 'There was an issue submitting your feedback. Please try again.';
          showAlert('Submission Error', errMsg);
        }
      } catch (err) {
        console.error('Feedback submit error:', err);
        feedbackForm.submit();
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalBtnText;
        }
      }
    });
  }

  // --------------------------------------------------------------------------
  // 8. BUTTON ACTIONS (LOGIN, SIGNUP, PLAN SELECTION, READ MORE)
  // --------------------------------------------------------------------------
  const loginBtn = document.getElementById('btn-login');
  const signupBtn = document.getElementById('btn-signup');
  const viewAllProfilesBtn = document.getElementById('btn-view-all-profiles');
  const readAboutBtn = document.getElementById('btn-read-about');

  if (loginBtn) {
    loginBtn.addEventListener('click', (e) => {
      window.location.href = 'bride-and-groom.html';
    });
  }

  if (signupBtn) {
    signupBtn.addEventListener('click', (e) => {
      window.location.href = 'signup.html';
    });
  }

  if (viewAllProfilesBtn) {
    viewAllProfilesBtn.addEventListener('click', () => {
      const filterSection = document.getElementById('match-filter-panel');
      if (filterSection) {
        filterSection.scrollIntoView({ behavior: 'smooth' });
      }
    });
  }

  if (readAboutBtn) {
    readAboutBtn.addEventListener('click', () => {
      showAlert('About Al-Nikah Matrimonial', 'Established to preserve Islamic traditions, Al-Nikah connects verified families with utmost discretion and respect.');
    });
  }

  // --------------------------------------------------------------------------
  // 5.5 MATRIMONIAL PACKAGES SLIDER
  // --------------------------------------------------------------------------
  const packagesViewport = document.getElementById('packages-slider');
  const packagesTrack = document.getElementById('packages-slider-track');
  const packagePrevBtn = document.getElementById('package-slider-prev');
  const packageNextBtn = document.getElementById('package-slider-next');
  const packageDotsWrap = document.getElementById('packages-slider-dots');

  if (packagesViewport && packagesTrack) {
    let currentPkgSlide = 0;
    let pkgAutoPlayTimer = null;

    const getPkgCards = () => Array.from(packagesTrack.querySelectorAll('.package-card'));

    const getPkgItemsPerView = () => {
      const w = window.innerWidth;
      if (w <= 680) return 1;
      if (w <= 1024) return 2;
      return 3;
    };

    const getPkgMaxIndex = () => {
      const cards = getPkgCards();
      const perView = getPkgItemsPerView();
      return Math.max(0, cards.length - perView);
    };

    const updatePkgSlider = (index) => {
      const cards = getPkgCards();
      const maxIdx = getPkgMaxIndex();

      if (index < 0) {
        currentPkgSlide = maxIdx;
      } else if (index > maxIdx) {
        currentPkgSlide = 0;
      } else {
        currentPkgSlide = index;
      }

      if (cards.length === 0) return;

      const cardEl = cards[0];
      const cardRect = cardEl.getBoundingClientRect();
      const gap = 24;
      const step = cardRect.width + gap;
      const offset = currentPkgSlide * step;

      packagesTrack.style.transform = `translateX(-${offset}px)`;

      if (packageDotsWrap) {
        const dots = packageDotsWrap.querySelectorAll('.package-dot');
        dots.forEach((dot, idx) => {
          const isCurrent = idx === currentPkgSlide;
          dot.classList.toggle('active', isCurrent);
          if (isCurrent) {
            dot.setAttribute('aria-current', 'true');
          } else {
            dot.removeAttribute('aria-current');
          }
        });
      }
    };

    const renderPkgDots = () => {
      if (!packageDotsWrap) return;
      packageDotsWrap.innerHTML = '';
      const maxIdx = getPkgMaxIndex();
      const totalDots = maxIdx + 1;

      if (totalDots <= 1) {
        packageDotsWrap.style.display = 'none';
        return;
      }
      packageDotsWrap.style.display = 'flex';

      for (let i = 0; i < totalDots; i++) {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = `package-dot ${i === currentPkgSlide ? 'active' : ''}`;
        dot.setAttribute('aria-label', `Go to packages slide ${i + 1}`);
        dot.addEventListener('click', () => {
          stopPkgAutoPlay();
          updatePkgSlider(i);
          startPkgAutoPlay();
        });
        packageDotsWrap.appendChild(dot);
      }
    };

    if (packageNextBtn) {
      packageNextBtn.addEventListener('click', () => {
        stopPkgAutoPlay();
        updatePkgSlider(currentPkgSlide + 1);
        startPkgAutoPlay();
      });
    }

    if (packagePrevBtn) {
      packagePrevBtn.addEventListener('click', () => {
        stopPkgAutoPlay();
        updatePkgSlider(currentPkgSlide - 1);
        startPkgAutoPlay();
      });
    }

    let pkgStartX = 0;
    packagesViewport.addEventListener('touchstart', (e) => {
      pkgStartX = e.changedTouches[0].screenX;
      stopPkgAutoPlay();
    }, { passive: true });

    packagesViewport.addEventListener('touchend', (e) => {
      const pkgEndX = e.changedTouches[0].screenX;
      const diff = pkgEndX - pkgStartX;
      if (Math.abs(diff) > 40) {
        if (diff > 0) updatePkgSlider(currentPkgSlide - 1);
        else updatePkgSlider(currentPkgSlide + 1);
      }
      startPkgAutoPlay();
    }, { passive: true });

    const startPkgAutoPlay = () => {
      stopPkgAutoPlay();
      pkgAutoPlayTimer = setInterval(() => {
        updatePkgSlider(currentPkgSlide + 1);
      }, 4500);
    };

    const stopPkgAutoPlay = () => {
      if (pkgAutoPlayTimer) {
        clearInterval(pkgAutoPlayTimer);
        pkgAutoPlayTimer = null;
      }
    };

    packagesViewport.addEventListener('mouseenter', stopPkgAutoPlay);
    packagesViewport.addEventListener('mouseleave', startPkgAutoPlay);

    window.addEventListener('resize', () => {
      renderPkgDots();
      updatePkgSlider(currentPkgSlide);
    });

    renderPkgDots();
    updatePkgSlider(0);
    startPkgAutoPlay();
  }

  // Plan Selection / Pay Now Buttons
  document.querySelectorAll('.select-plan-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const plan = btn.dataset.plan || 'Selected Plan';
      const price = btn.dataset.price ? ` (Offer Price: ₹${btn.dataset.price})` : '';
      showAlert(`Pay Now: ${plan}`, `Proceeding to checkout for ${plan}${price}. Our consultation desk will assist you with instant activation.`);
    });
  });

  // =========================================================
  // SUCCESS STORIES LIGHTBOX MODAL
  // =========================================================
  const storyLightboxModal = document.getElementById('story-lightbox-modal');
  const storyLightboxImg = document.getElementById('story-lightbox-img');
  const storyLightboxClose = document.getElementById('story-lightbox-close');
  const storyLightboxCaption = document.getElementById('story-lightbox-caption');
  const storyLightboxSubcaption = document.getElementById('story-lightbox-subcaption');

  let storyLightboxOpenedAt = 0;

  const openStoryLightbox = (imgSrc, altText) => {
    if (!storyLightboxModal || !storyLightboxImg) return;
    storyLightboxImg.src = imgSrc;
    storyLightboxImg.alt = altText || 'Al-Nikah Success Story';
    if (storyLightboxCaption) storyLightboxCaption.style.display = 'none';
    if (storyLightboxSubcaption) storyLightboxSubcaption.style.display = 'none';
    storyLightboxModal.removeAttribute('hidden');
    storyLightboxModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    storyLightboxOpenedAt = Date.now();
  };

  const closeStoryLightbox = (force = false) => {
    if (!force && Date.now() - storyLightboxOpenedAt < 200) return;
    if (!storyLightboxModal) return;
    storyLightboxModal.setAttribute('hidden', '');
    storyLightboxModal.style.display = 'none';
    if (storyLightboxImg) storyLightboxImg.src = '';
    document.body.style.overflow = '';
  };

  // Robust global event delegation for opening & closing
  document.addEventListener('click', (e) => {
    // 1. Close button clicked
    const closeBtn = e.target.closest('#story-lightbox-close');
    if (closeBtn) {
      e.preventDefault();
      e.stopPropagation();
      closeStoryLightbox(true);
      return;
    }

    // 2. Clicked on modal background (outside the image container)
    if (e.target === storyLightboxModal) {
      e.preventDefault();
      e.stopPropagation();
      closeStoryLightbox();
      return;
    }

    // 3. Clicked on the big image inside lightbox to close
    const clickedInsideWrap = e.target.closest('.story-lightbox-img-wrap');
    if (clickedInsideWrap && storyLightboxModal && !storyLightboxModal.hasAttribute('hidden')) {
      e.preventDefault();
      e.stopPropagation();
      closeStoryLightbox();
      return;
    }

    // 4. Clicked on any story card in marquee
    const card = e.target.closest('.story-card-item');
    if (card) {
      e.preventDefault();
      e.stopPropagation();
      const img = card.querySelector('img');
      const imgSrc = card.dataset.img || (img ? img.getAttribute('src') : '');
      const imgAlt = img ? img.getAttribute('alt') : 'Al-Nikah Success Story';
      if (imgSrc) {
        openStoryLightbox(imgSrc, imgAlt);
      }
      return;
    }
  });

  // Keyboard accessibility
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && storyLightboxModal && !storyLightboxModal.hasAttribute('hidden')) {
      closeStoryLightbox(true);
      return;
    }

    if (e.key === 'Enter' || e.key === ' ') {
      const activeCard = document.activeElement ? document.activeElement.closest('.story-card-item') : null;
      if (activeCard) {
        e.preventDefault();
        const img = activeCard.querySelector('img');
        const imgSrc = activeCard.dataset.img || (img ? img.getAttribute('src') : '');
        const imgAlt = img ? img.getAttribute('alt') : 'Al-Nikah Success Story';
        if (imgSrc) {
          openStoryLightbox(imgSrc, imgAlt);
        }
      }
    }
  });

  // Smooth Scroll for Internal Anchors
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', (e) => {
      const targetId = anchor.getAttribute('href');
      if (targetId && targetId !== '#') {
        const targetElement = document.querySelector(targetId);
        if (targetElement) {
          e.preventDefault();
          targetElement.scrollIntoView({ behavior: 'smooth' });
        }
      }
    });
  });

  // =========================================================
  // BACK TO TOP BUTTON (SCROLL DETECTOR & SMOOTH SCROLL)
  // =========================================================
  const backToTopBtn = document.getElementById('back-to-top');
  if (backToTopBtn) {
    const checkScrollPosition = () => {
      if (window.scrollY > 300) {
        backToTopBtn.classList.add('is-visible');
      } else {
        backToTopBtn.classList.remove('is-visible');
      }
    };

    window.addEventListener('scroll', checkScrollPosition, { passive: true });
    checkScrollPosition();

    backToTopBtn.addEventListener('click', (e) => {
      e.preventDefault();
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    });
  }
});

