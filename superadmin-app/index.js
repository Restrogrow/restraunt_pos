import { registerRootComponent } from 'expo';

// defines the background alerts task; it has to exist before the app (or a background wake-up) starts
import './utils/alerts';
import App from './App';

registerRootComponent(App);
