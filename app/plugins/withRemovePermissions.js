const fs = require('fs');
const path = require('path');
const { withDangerousMod } = require('expo/config-plugins');

// SYSTEM_ALERT_WINDOW ("draw over other apps") ends up in the final
// Android manifest even though no overlay UI exists anywhere in this app.
// A normal withAndroidManifest mod didn't reliably stick, so this rewrites
// the manifest file directly as a withDangerousMod (runs last, after every
// other plugin). Play Store's review process scrutinizes this permission
// heavily since it's tied to a narrow set of legitimate uses that don't
// apply here. RECORD_AUDIO is also listed, but something later in the
// Expo/RN autolinking chain still re-adds it even after this rewrite —
// that one needs a real fix (tracked separately), not just listed here.
// Runs as a config plugin (not a one-off edit to android/) so it survives
// every future `expo prebuild`, which regenerates that folder from scratch.
const REMOVE_PERMISSIONS = [
  'android.permission.SYSTEM_ALERT_WINDOW',
  'android.permission.RECORD_AUDIO',
];

module.exports = function withRemovePermissions(config) {
  return withDangerousMod(config, [
    'android',
    (config) => {
      const manifestPath = path.join(
        config.modRequest.platformProjectRoot,
        'app/src/main/AndroidManifest.xml'
      );
      let xml = fs.readFileSync(manifestPath, 'utf8');
      for (const perm of REMOVE_PERMISSIONS) {
        xml = xml.replace(
          new RegExp(`\\s*<uses-permission android:name="${perm}"\\s*/>\\n?`, 'g'),
          '\n'
        );
      }
      fs.writeFileSync(manifestPath, xml);
      return config;
    },
  ]);
};
