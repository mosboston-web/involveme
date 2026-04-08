/**
 * @file
 * Scans page links for ?involveid= parameters and converts them into
 * Involve.me popup triggers using the organization URL from drupalSettings.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const orgUrl = drupalSettings.involveme?.organizationUrl;

  Drupal.behaviors.involveMePopup = {
    attach(context) {
      if (!orgUrl) {
        return;
      }

      // Convert any ?involveid= links to Involve.me popup buttons.
      const links = once('involveme-popup', 'a[href*="involveid="]', context);
      links.forEach((link) => {
        let projectId;
        try {
          const url = new URL(link.href, window.location.href);
          projectId = url.searchParams.get('involveid');
        }
        catch {
          return;
        }

        if (!projectId) {
          return;
        }

        link.classList.add('involveme_popup');
        link.setAttribute('data-project', projectId);
        link.setAttribute('data-embed-mode', 'popup');
        link.setAttribute('data-trigger-event', 'button');
        link.setAttribute('data-popup-size', 'medium');
        link.setAttribute('data-organization-url', orgUrl);
        // Involve.me embed code normally has a user friendly title, but it is
        // not necessary and we don't want to deal with additional URL params.
        link.setAttribute('data-title', projectId);
        link.addEventListener('click', (e) => e.preventDefault());
      });

      // Embed blocks on the page require this library to be loaded, and will
      // use drupalSettings to pass trigger parameters.
      const popupTriggers = Object.values(
        drupalSettings.involveme?.popupTriggers ?? {}
      );

      // If there are no links or embeds, no need to load the library.
      if (links.length === 0 && popupTriggers.length === 0) {
        return;
      }

      // Inject the popup library only once, after all links are decorated.
      // The library will scan and find the decorated links on init, so we
      // defer loading it until now.
      once('involveme-popup-init', document.documentElement).forEach(() => {
        const script = document.createElement('script');
        script.src = `${orgUrl}/embed?type=popup`;
        if (popupTriggers.length > 0) {
          script.addEventListener('load', () => {
            const createTriggerEvent =
              window.involvemeEmbedPopup?.createTriggerEvent;

            if (typeof createTriggerEvent !== 'function') {
              console.warn('Involve.me popup API is unavailable after script load.');
              return;
            }

            popupTriggers.forEach((config) => {
              createTriggerEvent(config);
            });
          });
        }
        document.head.appendChild(script);
      });
    },
  };

}(Drupal, drupalSettings, once));
