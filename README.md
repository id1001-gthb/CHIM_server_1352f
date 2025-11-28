# CHIM server version 1.3.5.2f

Derived from version 1.3.5.2b stable and frozen.

Added stability fixes.
Added narration support.
Added few connection parameters.

This version is used as a test reference for MinAI plugin v2.1.3-dev7.
Contains the full MinAI plugin code v2.1.3-dev7.


# CHIM Server

Server for the Skyrim mod "CHIM". This component serves as a bridge between the SKSE plugin and various AI providers of text-to-speech, speech-to-text, and AI-based chat generators such as ChatGPT, MeloTTS, koboldcpp, Openrouter, XTTS, etc.

Ultimately you will have meaningful interactions with AI NPCs. 

## Other Features:
- Any NPC can be an AI, including group conversations.
- Long-term memory for in-game characters, employing various techniques to mitigate the lack of long-term memory in current LLMs (Language Model Models).
- Deep world awareness.
- An AI Narrator to narrate your adventures and provide help.
- Function calling action commands (e.g., trade with me, move here, attack that monster, etc.).
- Dynamic character personalities that update over your playthrough.

## Attributions
CHIM character biographies use material from the "Skyrim: Characters" articles on Unofficial Elder Scrolls Pages and are licensed under the Creative Commons Attribution-Share Alike License.

## CHIM Mod Page
[CHIM Skyrim Mod](https://www.nexusmods.com/skyrimspecialedition/mods/126330)

## Data

### Timestamps
gamets: skyrim internal time. starts at 10'000'000 on save game creation  
localts: unix timestamp of the server


