import os
import sys
import pandas as pd
import re
import string
import nltk
from nltk.tokenize import word_tokenize
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory
import simplemma
import joblib
import json
import base64
import io
from flask import Flask, request, jsonify
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.svm import SVC
from sklearn.pipeline import Pipeline

# --- Basic Flask App Setup ---
app = Flask(__name__)

# --- Global Variables & Model Loading ---
script_dir = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(script_dir, 'svm_model.pkl')
NORMALIZATION_DICT_PATH = os.path.join(script_dir, 'kamus_norm.txt')

pipeline = None
normalization_dict = {}
stopword_remover = None

def load_resources():
    global pipeline, normalization_dict, stopword_remover

    if os.path.exists(MODEL_PATH):
        try:
            pipeline = joblib.load(MODEL_PATH)
            print("Model loaded successfully.")
        except Exception as e:
            print(f"Error loading model: {e}")

    try:
        with open(NORMALIZATION_DICT_PATH, 'r', encoding='utf-8') as f:
            for line in f:
                parts = line.strip().split('\t')
                if len(parts) == 2:
                    normalization_dict[parts[0]] = parts[1]
        print("Normalization dictionary loaded.")
    except FileNotFoundError:
        print("Warning: kamus_norm.txt not found. Normalization will be skipped.")

    try:
        nltk.data.find('tokenizers/punkt')
    except LookupError:
        print("Downloading NLTK 'punkt' tokenizer...")
        nltk.download('punkt', quiet=True)

    factory = StopWordRemoverFactory()
    stopword_remover = factory.create_stop_word_remover()
    print("Sastrawi and Simplemma loaded.")

# --- Preprocessing Functions ---
def clean_text(text):
    text = text.lower()
    text = re.sub(r'@[A-Za-z0-9_]+', '', text)
    text = re.sub(r'#\w+', '', text)
    text = re.sub(r'RT[\s]+', '', text)
    text = re.sub(r'https?://\S+', '', text)
    text = re.sub(r'[^a-z\s]', '', text)
    text = text.strip()
    return text

def normalize_text(text):
    words = text.split()
    normalized_words = [normalization_dict.get(word, word) for word in words]
    return ' '.join(normalized_words)

def remove_stopwords(text):
    return stopword_remover.remove(text)

def lemmatize_text(text):
    lemmas = [simplemma.lemmatize(word, lang='id') for word in word_tokenize(text)]
    return ' '.join(lemmas)

def preprocess_text_series(text_series):
    processed = text_series.astype(str).apply(clean_text)
    processed = processed.apply(normalize_text)
    processed = processed.apply(remove_stopwords)
    processed = processed.apply(lemmatize_text)
    return processed

# --- Flask API Endpoints ---
@app.route('/train', methods=['POST'])
def train_model():
    global pipeline

    data = request.get_json()
    if not data or 'file_content' not in data:
        return jsonify({'error': 'Invalid request. Missing file_content.'}), 400

    try:
        file_bytes = base64.b64decode(data['file_content'])
        df_wide = pd.read_excel(io.BytesIO(file_bytes))

        all_data = []
        for i in range(1, 15):
            text_col = f'pertanyaan {i}'
            label_col = f'label {i}'

            if text_col in df_wide.columns and label_col in df_wide.columns:
                pair_df = df_wide[[text_col, label_col]].copy()
                pair_df.dropna(subset=[text_col], inplace=True)
                pair_df.rename(columns={text_col: 'text', label_col: 'label'}, inplace=True)
                all_data.append(pair_df)

        if not all_data:
            return jsonify({'error': "No valid 'pertanyaan X' and 'label X' column pairs found."}), 400

        df = pd.concat(all_data, ignore_index=True)

        df.dropna(subset=['label'], inplace=True)
        df['label'] = pd.to_numeric(df['label'], errors='coerce')
        df.dropna(subset=['label'], inplace=True)
        df['label'] = df['label'].astype(int)

        if df.empty:
            return jsonify({'error': "No valid numeric labels (0 or 1) found after cleaning the label columns."}), 400

        X = preprocess_text_series(df['text'])
        y = df['label']

        new_pipeline = Pipeline([
            ('tfidf', TfidfVectorizer()),
            ('svm', SVC(kernel='linear'))
        ])
        new_pipeline.fit(X, y)

        joblib.dump(new_pipeline, MODEL_PATH)
        pipeline = new_pipeline

        return jsonify({'message': 'Model trained and saved successfully.'}), 200

    except Exception as e:
        return jsonify({'error': f'An error occurred during training: {str(e)}'}), 500

@app.route('/predict', methods=['POST'])
def predict_sentiment():
    if pipeline is None:
        return jsonify({'error': 'Model is not loaded. Please train the model first.'}), 400

    data = request.get_json()
    if not data or 'file_content' not in data:
        return jsonify({'error': 'Invalid request. Missing file_content.'}), 400

    try:
        file_bytes = base64.b64decode(data['file_content'])
        df_wide = pd.read_excel(io.BytesIO(file_bytes))

        all_data_with_source = []
        for i in range(1, 15):
            text_col = f'pertanyaan {i}'
            if text_col in df_wide.columns:
                for text in df_wide[text_col].dropna():
                    all_data_with_source.append({
                        'text': text,
                        'source_question': text_col
                    })

        if not all_data_with_source:
            return jsonify({'error': "No 'pertanyaan X' columns found or all are empty."}), 400

        df = pd.DataFrame(all_data_with_source)
        if df.empty:
            return jsonify({'error': "No text data found to analyze."}), 400

        lemmatized_text = preprocess_text_series(df['text'])
        predictions = pipeline.predict(lemmatized_text)

        sentiment_map = {0: "Negatif", 1: "Positif"}
        df['sentimen_label'] = [sentiment_map.get(p, "Unknown") for p in predictions]

        results = df[['text', 'sentimen_label', 'source_question']].to_dict(orient='records')
        return jsonify(results), 200

    except Exception as e:
        return jsonify({'error': f'An error occurred during prediction: {str(e)}'}), 500

if __name__ == '__main__':
    print("Starting Flask server...")
    load_resources()
    app.run(host='127.0.0.1', port=5001, debug=False)
