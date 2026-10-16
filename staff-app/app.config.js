// app.json holds the config; this only lets the browser demo be exported into a sub-folder of
// the website (EXPO_BASE_URL=/staff-app-demo/app). Phone builds don't set it and are unaffected.
module.exports = ({ config }) => ({
  ...config,
  experiments: {
    ...(config.experiments || {}),
    ...(process.env.EXPO_BASE_URL ? { baseUrl: process.env.EXPO_BASE_URL } : {}),
  },
});
