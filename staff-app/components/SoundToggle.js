import { Ionicons } from '@expo/vector-icons';
import { StyleSheet } from 'react-native';
import { setAlertSound, useAlertSound } from '../utils/alertSound';
import { feedback } from './ui/Feedback';
import PressableScale from './ui/PressableScale';

// The bell in a header: alert sounds on / off for this phone (buzz and banner stay on).
export default function SoundToggle({ light = true }) {
  const on = useAlertSound();
  const flip = () => {
    setAlertSound(!on);
    feedback.info(on ? 'Alert sounds off. You still get a buzz and a banner.' : 'Alert sounds on');
  };
  return (
    <PressableScale
      style={[styles.btn, { backgroundColor: light ? 'rgba(255,255,255,0.18)' : 'rgba(29,27,38,0.06)' }]}
      onPress={flip}
      hitSlop={6}
      accessibilityLabel={on ? 'Mute alert sounds' : 'Turn alert sounds on'}
    >
      <Ionicons name={on ? 'volume-high' : 'volume-mute'} size={19} color={light ? '#fff' : '#1D1B26'} />
    </PressableScale>
  );
}

const styles = StyleSheet.create({
  btn: { width: 40, height: 40, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
});
